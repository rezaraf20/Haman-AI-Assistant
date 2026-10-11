<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Mail\VerifyEmail;
use App\Models\{Plan, User};
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * The recovery funnel's first piece (2026-10-10): someone who signs up,
 * closes the tab before clicking the emailed link, and comes back later
 * used to get a bare "email already taken" validation error with no way
 * forward except support. See LandingSignupController::register()'s own
 * comment on the resend branch, and sendVerification()'s on the nonce.
 */
class EmailVerificationResendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_monthly' => 0, 'max_chatbots' => 1,
            'max_tokens_monthly' => 100000, 'is_active' => true, 'sort_order' => 0,
        ]);
        Settings::set('mail.host', 'smtp.test.invalid');
        Settings::set('mail.from_address', 'test@example.test');
    }

    private function signUp(string $email, string $token = 'test-token'): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['_token' => $token])->post('https://hamanai.com/signup', [
            '_token' => $token, 'name' => 'Test', 'email' => $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
            'country' => 'IR',
        ]);
    }

    public function test_registering_with_an_existing_unverified_email_resends_instead_of_erroring(): void
    {
        Mail::fake();

        $this->signUp('again@example.test')->assertRedirect();
        $first = User::where('email', 'again@example.test')->first();
        $this->assertNotNull($first);

        $this->signUp('again@example.test', 'second-token')
            ->assertRedirect()
            ->assertSessionHas('landing_status', __('auth_email.verification_resent', ['email' => 'again@example.test']));

        // Still exactly one user row -- a resend must never create a
        // second account for the same address.
        $this->assertSame(1, User::where('email', 'again@example.test')->count());
        Mail::assertSentCount(2);
    }

    public function test_the_resend_rate_limit_is_per_email_not_per_ip(): void
    {
        Mail::fake();
        Settings::set('limits.verification_resend_per_hour', 2);

        $this->signUp('capped@example.test');

        // Via /resend-verification directly, not another /signup POST --
        // /signup carries its own route-level throttle:register (the
        // per-IP-per-DAY tenant-creation cap, default 3), which a 4th call
        // to /signup in one test would hit first and mask what this test
        // is actually checking (confirmed: that mistake gave a 429 from
        // the OUTER limiter, nothing to do with the per-email one this
        // test means to exercise). /resend-verification was deliberately
        // kept off that limiter for exactly this reason -- see its own
        // route comment in routes/web.php.
        $resend = fn (string $token) => $this->withSession(['_token' => $token])->post('/resend-verification', [
            '_token' => $token, 'email' => 'capped@example.test',
        ]);

        // The cap of 2/hour: two resends here are exactly at it, and a
        // third is refused.
        $resend('token-2');
        $resend('token-3');
        $response = $resend('token-4');

        $response->assertSessionHas('landing_status', fn ($msg) => str_contains($msg, 'Too many attempts') || str_contains($msg, 'تلاش'));
        Mail::assertSentCount(3); // the initial send + the two allowed resends, not the refused third
    }

    public function test_an_old_link_is_invalidated_by_a_resend(): void
    {
        Mail::fake();

        $this->signUp('reinvite@example.test');
        $user = User::where('email', 'reinvite@example.test')->first();
        $oldNonce = $user->email_verification_nonce;
        $this->assertNotNull($oldNonce);

        $this->signUp('reinvite@example.test', 'second-token');
        $newNonce = $user->fresh()->email_verification_nonce;

        $this->assertNotNull($newNonce);
        $this->assertNotSame($oldNonce, $newNonce, 'a resend must issue a different nonce, or the old link would still work');

        // The OLD link, built with the OLD nonce, must no longer verify.
        $oldLink = \Illuminate\Support\Facades\URL::temporarySignedRoute('verify.email', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email), 'nonce' => $oldNonce,
        ]);
        $this->get($oldLink)->assertRedirect();
        $this->assertNull($user->fresh()->email_verified_at, 'the superseded link must not verify the account');

        // The NEW link still does.
        $newLink = \Illuminate\Support\Facades\URL::temporarySignedRoute('verify.email', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email), 'nonce' => $newNonce,
        ]);
        $this->get($newLink)->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_the_public_resend_form_works_without_login(): void
    {
        Mail::fake();

        // Created directly (not via the full /signup POST) specifically so
        // this test starts as a genuine guest -- no prior Auth::login() in
        // this run at all -- since the whole point of this endpoint is
        // reaching someone with no session, on a browser that never had one.
        $tenantService = app(\App\Services\TenantService::class);
        $result = $tenantService->registerViaEmail([
            'name' => 'No Auth', 'email' => 'noauth@example.test', 'password' => 'password123', 'country' => 'IR',
        ]);
        $user = $result['user'];
        $oldNonce = $user->email_verification_nonce;
        $this->assertNull($oldNonce, 'no verification email has been sent yet at this point');
        $this->assertGuest('web');

        $response = $this->withSession(['_token' => 'resend-token'])->post('/resend-verification', [
            '_token' => 'resend-token', 'email' => 'noauth@example.test',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('landing_status', __('auth_email.verification_resent', ['email' => 'noauth@example.test']));
        $this->assertNotNull($user->fresh()->email_verification_nonce);
        $this->assertGuest('web', 'the public resend form must not log anyone in');
        Mail::assertSentCount(1);
    }

    public function test_the_public_resend_form_handles_an_unknown_email_without_error(): void
    {
        Mail::fake();

        $response = $this->withSession(['_token' => 'resend-token'])->post('/resend-verification', [
            '_token' => 'resend-token', 'email' => 'nobody-here@example.test',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('landing_status', __('auth_email.no_account_for_address'));
        Mail::assertNothingSent();
    }

    public function test_an_already_verified_duplicate_email_still_fails_normal_validation(): void
    {
        Mail::fake();

        $this->signUp('verified@example.test');
        $user = User::where('email', 'verified@example.test')->first();
        $user->forceFill(['email_verified_at' => now()])->save();

        // Registering again with the SAME, now-verified email must behave
        // exactly as before this feature existed: a real conflict, not a
        // resend.
        $this->signUp('verified@example.test', 'second-token')
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'verified@example.test')->count());
    }

    public function test_the_check_email_page_renders_with_the_address(): void
    {
        $response = $this->get('/check-email?email=someone@example.test');

        $response->assertOk();
        $response->assertSee('someone@example.test');
        $response->assertSee(__('auth_email.check_email_title'));
    }
}
