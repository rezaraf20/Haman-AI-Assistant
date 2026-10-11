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
 * Who this is actually for: email+password accounts. A phone+OTP signup
 * (registerViaPhone()) also has a `password` column, but set to an
 * unguessable random value never meant for login — auth there is phone+
 * OTP only (see that method's own comment). This reset flow is keyed on
 * email alone and does not distinguish the two: a phone-signup tenant who
 * also has a real email on file CAN request a reset and will end up with
 * a real, usable password afterward, which quietly also enables email+
 * password login for an account that was never meant to use it. Nothing
 * in the product today forbids that, so this does not block it — flagging
 * it here because it is the one real behavioural edge this reuses rather
 * than a deliberate design decision made for this feature specifically.
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

        $user = User::where('email', $data['email'])->first();
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
