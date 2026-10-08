<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Plan};
use App\Livewire\EmailLogin;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash, Mail};
use Livewire\Livewire;

/**
 * English-locale counterpart to the phone+SMS-OTP flow: registration and
 * login via email+password, added because the portal previously had no
 * way at all for a non-Persian-speaking customer to sign up or log in
 * (phone+OTP was the only path, hardcoded regardless of language).
 */
class EmailLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();
        // TenantService::registerViaEmail() (same as registerViaPhone())
        // requires a plan with this exact slug to exist — it's the new
        // tenant's default plan, not something the registration flow
        // creates itself.
        Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 100000,
            'is_active' => true, 'sort_order' => 0,
        ]);
    }

    public function test_portal_login_page_shows_email_form_for_english_method(): void
    {
        $response = $this->get('/portal/login?method=email');
        $response->assertOk();
        $response->assertSeeLivewire(EmailLogin::class);
    }

    public function test_portal_login_page_shows_phone_form_for_persian_method(): void
    {
        $response = $this->get('/portal/login?method=phone');
        $response->assertOk();
        $response->assertSeeLivewire(\App\Livewire\OtpLogin::class);
    }

    private function configureSmtp(): void
    {
        Settings::set('mail.host', 'smtp.example.test');
        Settings::set('mail.from_address', 'bot@example.test');
    }

    public function test_registering_via_email_creates_tenant_and_logs_in(): void
    {
        $this->configureSmtp();
        Mail::fake();

        Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'Jane Doe')
            ->set('email', 'jane@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('mountedAt', now()->timestamp - 30) // a real human takes more than an instant — see SignupRisk::blockReason()
            ->call('submitRegister')
            ->assertRedirect('/portal');

        $user = User::where('email', 'jane@example.test')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->tenant_id);
        $this->assertEquals('Jane', $user->first_name);
        $this->assertEquals('Doe', $user->last_name);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNull($user->email_verified_at, 'a Livewire signup must not be pre-verified any more than the landing page one is');
        Mail::assertSentCount(1);
    }

    /** Real abuse vector this closes: an unverified email used to get an immediately-active trial chatbot with 200 free LLM messages. */
    public function test_the_trial_chatbot_is_inactive_until_the_email_is_verified(): void
    {
        $this->configureSmtp();
        Mail::fake();

        Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'Pending User')
            ->set('email', 'pending@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('mountedAt', now()->timestamp - 30)
            ->call('submitRegister');

        $user = User::where('email', 'pending@example.test')->first();
        $index = \Illuminate\Support\Facades\DB::table('chatbot_index')->where('tenant_id', $user->tenant_id)->first();
        $this->assertFalse((bool) $index->is_active, 'the trial chatbot must not be active before the email is verified');
        $this->assertEquals('pending_verification', $index->disabled_reason);
    }

    public function test_registration_is_refused_without_smtp_configured(): void
    {
        Mail::fake();

        Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'No Smtp')
            ->set('email', 'nosmtp@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('mountedAt', now()->timestamp - 30)
            ->call('submitRegister')
            ->assertSet('error', __('auth_email.smtp_unavailable'));

        $this->assertNull(User::where('email', 'nosmtp@example.test')->first(), 'no account should be created when verification could never be sent');
        Mail::assertNothingSent();
    }

    /** The exact scenario asked for: two signups from one IP, the second refused. */
    public function test_a_second_signup_from_the_same_ip_in_one_day_is_refused(): void
    {
        $this->configureSmtp();
        Mail::fake();
        Settings::set('limits.register_per_ip_per_day', 1);

        Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'First User')
            ->set('email', 'first@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('mountedAt', now()->timestamp - 30)
            ->call('submitRegister')
            ->assertRedirect('/portal');

        $second = Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'Second User')
            ->set('email', 'second@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('mountedAt', now()->timestamp - 30)
            ->call('submitRegister');

        $this->assertNotEmpty($second->get('error'), 'a second signup over the per-IP daily cap must show an error, not silently succeed or fail');

        $this->assertNotNull(User::where('email', 'first@example.test')->first());
        $this->assertNull(User::where('email', 'second@example.test')->first(), 'a second signup from the same IP, over the daily cap, must not create a tenant');
    }

    public function test_registering_with_duplicate_email_fails_validation(): void
    {
        User::create([
            'email' => 'taken@example.test',
            'password' => Hash::make('irrelevant'),
            'password_hash' => Hash::make('irrelevant'),
            'name' => 'Someone Else',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'Someone')
            ->set('email', 'taken@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('mountedAt', now()->timestamp - 30)
            ->call('submitRegister')
            ->assertHasErrors(['email']);
    }

    public function test_login_with_correct_password_succeeds(): void
    {
        $user = User::create([
            'email' => 'existing@example.test',
            'password' => Hash::make('correct-password'),
            'password_hash' => Hash::make('correct-password'),
            'name' => 'Existing User',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        Livewire::test(EmailLogin::class)
            ->set('email', 'existing@example.test')
            ->set('password', 'correct-password')
            ->call('submitLogin')
            ->assertRedirect('/portal');

        $this->assertAuthenticatedAs($user->fresh(), 'web');
    }

    public function test_login_with_wrong_password_shows_error_not_a_500(): void
    {
        User::create([
            'email' => 'existing2@example.test',
            'password' => Hash::make('correct-password'),
            'password_hash' => Hash::make('correct-password'),
            'name' => 'Existing User 2',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        Livewire::test(EmailLogin::class)
            ->set('email', 'existing2@example.test')
            ->set('password', 'wrong-password')
            ->call('submitLogin')
            ->assertSet('error', __('validation.invalid_credentials'));

        $this->assertGuest('web');
    }
}
