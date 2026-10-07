<?php
namespace App\Http\Controllers;

use App\Support\BrandDomains;
use App\Mail\VerifyEmail;
use App\Models\User;
use App\Services\TenantService;
use App\Support\{Countries, MailSettings};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Log, Mail, URL};
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

        $data['signup_risk'] = \App\Support\SignupRisk::compute(
            $request->userAgent(),
            $request->input(\App\Support\SignupRisk::HONEYPOT_FIELD),
            $request->filled('form_rendered_at') ? (now()->timestamp - (float) $request->input('form_rendered_at')) : null,
        );

        $result = $tenants->registerViaEmail($data);
        $user = $result['user'];

        $this->sendVerification($user);

        Auth::guard('web')->login($user, remember: true);

        return redirect(BrandDomains::appUrl('/portal'))->with('landing_status', __('auth_email.verification_sent', ['email' => $user->email]));
    }

    /** Signed, expiring, and tied to the user's current address. */
    public function sendVerification(User $user): bool
    {
        $link = URL::temporarySignedRoute('verify.email', now()->addHours(48), [
            'id'   => $user->id,
            'hash' => sha1($user->email),
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

    public function verify(Request $request, string $id, string $hash)
    {
        $user = User::find($id);

        // The hash is over the address the link was issued for, so a link
        // stops working once the address changes.
        if (!$user || !hash_equals(sha1($user->email), $hash)) {
            return redirect(BrandDomains::appUrl('/portal'))->with('landing_status', __('auth_email.verify_invalid'));
        }

        if (!$user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
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

    public function resend(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return redirect(BrandDomains::appUrl('/portal/login'));
        }
        if ($user->email_verified_at) {
            return back()->with('landing_status', __('auth_email.already_verified'));
        }
        if (!MailSettings::isUsable()) {
            return back()->with('landing_status', __('auth_email.smtp_unavailable'));
        }

        $sent = $this->sendVerification($user);

        return back()->with('landing_status', $sent
            ? __('auth_email.verification_sent', ['email' => $user->email])
            : __('auth_email.send_failed'));
    }
}
