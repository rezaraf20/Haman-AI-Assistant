<?php
namespace App\Http\Controllers;

use App\Mail\ResetPasswordLink;
use App\Models\User;
use App\Support\{BrandDomains, MailSettings, Settings};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Hash, Log, Mail, RateLimiter, URL};
use Illuminate\Support\Str;

/**
 * Password reset, from scratch (2026-10-10) — this app has no password-
 * broker table or default Laravel scaffolding at all; it never existed
 * before this. Deliberately mirrors LandingSignupController's own signed-
 * URL + single-use-nonce pattern (see VerifyEmail/sendVerification's own
 * docblocks) rather than Laravel's built-in Password facade, for the exact
 * same reason: one proven-correct plain-text-email-link shape, not two.
 *
 * Who this is actually for: email+password accounts, gated on
 * email_verified_at (see sendResetLink()) — no account can get a reset
 * link until something has actually confirmed it owns that address.
 *
 * A phone+OTP signup (registerViaPhone()) also has a `password` column,
 * set to an unguessable random value never meant for login — auth there
 * is phone+OTP only (see that method's own comment). registerViaPhone()
 * stamps email_verified_at = now() unconditionally at signup (the phone
 * number was just proven by a real OTP; the email on file is simply
 * trusted, not separately confirmed), so a phone-signup tenant passes the
 * same email_verified_at gate as anyone else and CAN request a reset,
 * ending up with a real, usable password that also quietly enables
 * email+password login for an account that was never meant to use it.
 * Confirmed on production 2026-10-11: 2 of 8 users have phone filled, both
 * already have email_verified_at set (0 with phone filled and it null) —
 * so today this path only ever reaches an account whose email was already
 * trusted one way or another, by registration design, not by accident.
 * Nothing in the product forbids a phone-signup user ending up with a
 * real password this way, so this does not block it — flagging it here
 * because it is a real behavioural edge this reuses rather than a
 * deliberate decision made for this feature specifically.
 *
 * If a future path ever creates a user with phone filled and
 * email_verified_at null (there is none today), that account could never
 * get a reset link and also has no "verify my email" button anywhere in
 * the portal — this reset flow is the only thing that currently sets
 * email_verified_at after initial signup. That combination should be
 * caught before it ships, not discovered by a stuck user.
 */
class PasswordResetController extends Controller
{
    public function showRequestForm(Request $request)
    {
        return view('landing.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);

        $cap = (int) Settings::get('limits.password_reset_request_per_hour');
        $key = 'password-reset:' . $data['email'];
        if ($cap > 0 && RateLimiter::tooManyAttempts($key, $cap)) {
            return back()->with('landing_status', __('validation.too_many_attempts', ['seconds' => RateLimiter::availableIn($key)]));
        }
        if ($cap > 0) RateLimiter::hit($key, 3600);

        // email_verified_at gates this the same way it gates
        // createTrialChatbot()'s is_active: an address nobody has proven
        // belongs to the account holder (unverified email+password signup
        // still mid-funnel, or any future path that leaves this null)
        // doesn't get a reset link either — see the class docblock for why
        // phone+OTP accounts, which registerViaPhone() always marks
        // verified at signup, pass this check and are unaffected.
        $user = User::where('email', $data['email'])->whereNotNull('email_verified_at')->first();
        if ($user && MailSettings::isUsable()) {
            $this->sendResetEmail($user);
        }

        // Deliberately the SAME message regardless of whether an account
        // exists for this address — unlike the verification-resend flow
        // (which already reveals "this email is taken" via the signup
        // form's own unique:users,email error, so there is nothing left to
        // leak there), "forgot password" telling an attacker which
        // addresses have accounts at all IS the textbook information leak,
        // so this one stays generic on purpose.
        return back()->with('landing_status', __('auth_email.reset_link_sent_generic'));
    }

    /**
     * Same nonce purpose as LandingSignupController::sendVerification():
     * a signed URL has nothing server-side to revoke, so a second reset
     * request must invalidate whatever link the first one sent, not leave
     * two live links until the shared 1-hour expiry catches up.
     */
    private function sendResetEmail(User $user): void
    {
        $nonce = Str::random(32);
        $user->forceFill(['password_reset_nonce' => $nonce])->save();

        $link = URL::temporarySignedRoute('password.reset', now()->addHour(), [
            'id'    => $user->id,
            'hash'  => sha1($user->email),
            'nonce' => $nonce,
        ]);

        try {
            MailSettings::apply();
            Mail::to($user->email)->send(new ResetPasswordLink($user->name, $link));
        } catch (\Throwable $e) {
            Log::warning('Password reset email failed for ' . $user->email . ': ' . $e->getMessage());
        }
    }

    /**
     * $id/$hash/$nonce all check out, or this is not a link worth acting
     * on — the 'signed' middleware already rejected a bad signature or an
     * expired link before this method ever runs (see routes/web.php); this
     * is the second layer, same as verify() — an address that's since
     * changed, or a link superseded by a newer request.
     */
    private function validLinkUser(string $id, string $hash, string $nonce): ?User
    {
        $user = User::find($id);
        if (!$user || !hash_equals(sha1($user->email), $hash)) return null;
        if (!$user->password_reset_nonce || !hash_equals($user->password_reset_nonce, $nonce)) return null;

        return $user;
    }

    public function showResetForm(Request $request, string $id, string $hash, string $nonce)
    {
        if (!$this->validLinkUser($id, $hash, $nonce)) {
            return redirect(BrandDomains::appUrl('/forgot-password'))->with('landing_status', __('auth_email.reset_link_invalid'));
        }

        return view('landing.reset-password', [
            'id' => $id, 'hash' => $hash, 'nonce' => $nonce,
            // The form POSTs back to this exact signed path+query (expires/
            // signature carried along as hidden fields) so the POST hits
            // the identical signed route rather than needing a signature of
            // its own.
            'query' => $request->query(),
        ]);
    }

    public function resetPassword(Request $request, string $id, string $hash, string $nonce)
    {
        $user = $this->validLinkUser($id, $hash, $nonce);
        if (!$user) {
            return redirect(BrandDomains::appUrl('/forgot-password'))->with('landing_status', __('auth_email.reset_link_invalid'));
        }

        $data = $request->validate(['password' => 'required|string|min:8|confirmed']);

        $user->forceFill([
            'password'             => Hash::make($data['password']),
            'password_hash'        => Hash::make($data['password']),
            'password_reset_nonce' => null,
        ])->save();

        Auth::guard('web')->login($user, remember: true);

        return redirect(BrandDomains::appUrl('/portal'))->with('landing_status', __('auth_email.reset_done'));
    }
}
