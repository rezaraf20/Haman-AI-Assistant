<?php
namespace App\Http\Controllers;

use App\Support\BrandDomains;
use App\Mail\VerifyEmail;
use App\Models\User;
use App\Services\TenantService;
use App\Support\{Countries, MailSettings, Settings, SignupRisk};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Log, Mail, RateLimiter, URL};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Email + password signup, as the second route in.
 *
 * The existing OtpLogin path only accepts an Iranian mobile number, which is
 * a hard stop for anyone outside Iran. This adds email, with verification,
 * using the SMTP credentials from the settings page.
 *
 * If SMTP is not configured the route refuses rather than creating an account
 * nobody can verify — and the landing page does not render the form at all in
 * that case, so this is the backstop, not the only check.
 */
class LandingSignupController extends Controller
{
    public function register(Request $request, TenantService $tenants)
    {
        $secondsToSubmit = $request->filled('form_rendered_at')
            ? (now()->timestamp - (float) $request->input('form_rendered_at'))
            : null;

        // Checked before anything else, including the mail-usability gate
        // below: a bot that trips this should see exactly the same thing
        // regardless of this platform's own mail configuration, and must
        // never learn anything real about why — no validation errors, no
        // account, nothing. See SignupRisk::blockReason()'s own docblock
        // for why only these two signals are certain enough to refuse on.
        $blockReason = SignupRisk::blockReason($request->input(SignupRisk::HONEYPOT_FIELD), $secondsToSubmit);
        if ($blockReason) {
            SignupRisk::recordBlock($blockReason, 'landing', $request->ip(), $request->userAgent());

            return redirect($this->checkEmailUrl((string) $request->input('email')));
        }

        // Real reported gap (2026-10-10): someone who signs up, closes the
        // tab before clicking the emailed link, and comes back later to try
        // again got a bare "email already taken" validation error with no
        // way forward except support. Resend instead, for exactly this one
        // case — an account that exists but was never verified is not a
        // real conflict yet. A VERIFIED duplicate still falls through to
        // the normal unique:users,email failure below.
        $email = (string) $request->input('email');
        if ($email !== '') {
            $existing = User::where('email', $email)->whereNull('email_verified_at')->first();
            if ($existing) {
                $result = $this->resendVerificationFor($existing);
                return redirect($this->checkEmailUrl($email))->with('landing_status', $result['message']);
            }
        }

        if (!MailSettings::isUsable()) {
            return back()->with('landing_status', __('landing.signup_email_disabled'));
        }

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            // Decides the tenant's currency for the rest of their time in
            // the portal (Tenant::currency()) — see App\Support\Countries.
            // A closed list, not a free-text country name: Rule::in against
            // the exact set the dropdown offers, so a tampered request can't
            // store a value the currency logic was never built to handle.
            'country'  => ['required', 'string', Rule::in(array_keys(Countries::all()))],
        ]);

        $data['signup_risk'] = SignupRisk::compute($request->userAgent(), $request->input(SignupRisk::HONEYPOT_FIELD), $secondsToSubmit);

        $result = $tenants->registerViaEmail($data);
        $user = $result['user'];

        $this->sendVerification($user);

        Auth::guard('web')->login($user, remember: true);

        // Not /portal: under signup.require_email_verification=true there
        // is nothing there yet (no schema, no chatbot — see
        // TenantService::provisionVerifiedTenant()), and this flash message
        // has nowhere to render on a Filament panel page anyway (confirmed
        // 2026-10-10: landing_status was never read by any Filament view,
        // so every "check your email" notice since this flow existed was
        // silently lost the moment the redirect landed on /portal). This
        // dedicated page is the fix for both problems at once.
        return redirect($this->checkEmailUrl($user->email))
            ->with('landing_status', __('auth_email.verification_sent', ['email' => $user->email]));
    }

    /** The "check your email" landing page itself — see register()'s own comment on why this exists instead of redirecting to /portal. */
    public function checkEmailPage(Request $request)
    {
        return view('landing.check-email', [
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * BrandDomains::appUrl() is what makes this cross a domain boundary
     * correctly (hamanai.com -> app.hamanai.com) or deliberately NOT cross
     * one (api.arshanweb.ir stays self-contained) — the plain route()
     * helper would just resolve against whatever host the current request
     * happened to arrive on, silently breaking that guarantee the moment
     * this page stopped being /portal. See BrandDomains's own docblock.
     */
    private function checkEmailUrl(string $email): string
    {
        return BrandDomains::appUrl('/check-email') . '?' . http_build_query(['email' => $email]);
    }

    /**
     * Signed, expiring, and tied to the user's current address — plus a
     * nonce that makes this the ONLY currently-valid link for this user.
     * A signed URL is a stateless HMAC with nothing server-side to revoke,
     * so without the nonce a resend would leave the PREVIOUS link just as
     * valid as the new one until its own 48h expiry — two active links at
     * once, which is exactly what the nonce exists to prevent. Called both
     * at signup and on every resend, so both paths share one guarantee:
     * issuing a new link always kills whatever link came before it.
     */
    public function sendVerification(User $user): bool
    {
        $nonce = Str::random(32);
        $user->forceFill(['email_verification_nonce' => $nonce])->save();

        $link = URL::temporarySignedRoute('verify.email', now()->addHours(48), [
            'id'    => $user->id,
            'hash'  => sha1($user->email),
            'nonce' => $nonce,
        ]);

        try {
            MailSettings::apply();
            Mail::to($user->email)->send(new VerifyEmail($user->name, $link));
            return true;
        } catch (\Throwable $e) {
            // The account exists and they are signed in; a failed send is
            // recoverable by asking for another link, so it must not throw
            // them out of a completed signup.
            Log::warning('Verification email failed for ' . $user->email . ': ' . $e->getMessage());
            return false;
        }
    }

    public function verify(Request $request, string $id, string $hash, string $nonce)
    {
        $user = User::find($id);

        // The hash is over the address the link was issued for, so a link
        // stops working once the address changes. The nonce must match the
        // MOST RECENT send — see sendVerification()'s own docblock; this is
        // what actually invalidates an earlier resend's link rather than
        // just the 48h expiry eventually catching up to it.
        $nonceOk = $user && $user->email_verification_nonce && hash_equals($user->email_verification_nonce, $nonce);
        if (!$user || !hash_equals(sha1($user->email), $hash) || (!$user->email_verified_at && !$nonceOk)) {
            return redirect(BrandDomains::appUrl('/portal'))->with('landing_status', __('auth_email.verify_invalid'));
        }

        if (!$user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now(), 'email_verification_nonce' => null])->save();
            // Two calls covering both signup.require_email_verification
            // modes (see TenantService::provisionVerifiedTenant()'s own
            // docblock): ON, this is the first moment any real resource
            // gets created at all; OFF, the resources already exist from
            // registration and this is what flips the chatbot active.
            // Each is a no-op under the mode it doesn't apply to.
            if ($user->tenant) {
                $tenantService = app(TenantService::class);
                $tenantService->provisionVerifiedTenant($user->tenant, $user, $user->locale === 'fa' ? 'fa' : 'en');
                $tenantService->activateChatbotsPendingVerification($user->tenant);
            }
        }

        if (!Auth::check()) {
            Auth::guard('web')->login($user, remember: true);
        }

        return redirect(BrandDomains::appUrl('/portal'))->with('landing_status', __('auth_email.verify_done'));
    }

    /** Authenticated resend — the user is already logged in (just unverified) and asks again from inside the portal. Delegates to the same shared, rate-limited path resendPublic() uses. */
    public function resend(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return redirect(BrandDomains::appUrl('/portal/login'));
        }

        $result = $this->resendVerificationFor($user);

        return back()->with('landing_status', $result['message']);
    }

    /**
     * Public "I didn't get the email" form on the check-email page itself —
     * no login required, since the whole point is reaching someone who
     * never got far enough to be in a session (closed the tab, came back a
     * day later). Looks the email up itself rather than trusting a hidden
     * field, same reasoning as the signup-time duplicate-detection branch.
     */
    public function resendPublic(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $user = User::where('email', $data['email'])->first();

        $message = $user
            ? $this->resendVerificationFor($user)['message']
            : __('auth_email.no_account_for_address');

        return redirect($this->checkEmailUrl($data['email']))->with('landing_status', $message);
    }

    /**
     * The one place that actually sends (or refuses to send) a resend —
     * register()'s duplicate-detection branch, the authenticated resend(),
     * and the public resendPublic() form all call this, so the rate limit
     * and the "already verified" / "SMTP down" messages can never drift
     * apart between the three entry points.
     *
     * Keyed by EMAIL, not IP (unlike the register route's own per-IP
     * throttle): the abuse this guards against is someone hammering ONE
     * address's inbox, which an IP-keyed limit would not catch for an
     * attacker spreading requests across IPs, and would wrongly throttle
     * a shared office IP sending for many different addresses.
     *
     * Public (not private) specifically so EmailLogin::submitRegister()
     * can call into this same instance via app(LandingSignupController::
     * class) — the exact pattern that method already used for
     * sendVerification(), kept consistent rather than duplicating the rate
     * limit and message logic a second time for the Livewire path.
     *
     * @return array{ok: bool, message: string}
     */
    public function resendVerificationFor(User $user): array
    {
        if ($user->email_verified_at) {
            return ['ok' => false, 'message' => __('auth_email.already_verified')];
        }

        $cap = (int) Settings::get('limits.verification_resend_per_hour');
        $key = 'verify-resend:' . $user->email;
        if ($cap > 0 && RateLimiter::tooManyAttempts($key, $cap)) {
            return ['ok' => false, 'message' => __('validation.too_many_attempts', ['seconds' => RateLimiter::availableIn($key)])];
        }

        if (!MailSettings::isUsable()) {
            return ['ok' => false, 'message' => __('auth_email.smtp_unavailable')];
        }

        if ($cap > 0) RateLimiter::hit($key, 3600);

        $sent = $this->sendVerification($user);

        return $sent
            ? ['ok' => true, 'message' => __('auth_email.verification_resent', ['email' => $user->email])]
            : ['ok' => false, 'message' => __('auth_email.send_failed')];
    }
}
