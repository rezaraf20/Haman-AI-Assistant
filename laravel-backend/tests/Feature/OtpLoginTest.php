<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Livewire\OtpLogin;
use App\Models\{Plan, User};
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * completeProfile() creates a tenant and a trial chatbot exactly like
 * EmailLogin::submitRegister() does, but had no rate limit of its own until
 * now — the LivewireActionAudit finding that closed this. sendCode/
 * resendCode already have their own SMS-focused test coverage in
 * BackupAndAbuseTest; this covers the registration step specifically.
 */
class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();
        Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 100000,
            'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function fillProfileStep($test, string $phone, string $email)
    {
        return $test
            ->set('step', 'profile')
            ->set('phone', $phone)
            ->set('first_name', 'Test')
            ->set('last_name', 'User')
            ->set('national_id', '1234567890')
            ->set('email', $email)
            ->set('address', 'Test address');
    }

    public function test_completing_the_profile_creates_a_tenant_and_logs_in(): void
    {
        $this->fillProfileStep(Livewire::test(OtpLogin::class), '09121234567', 'phoneuser@example.test')
            ->call('completeProfile')
            ->assertRedirect('/portal');

        $user = User::where('email', 'phoneuser@example.test')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->tenant_id);
        $this->assertAuthenticatedAs($user, 'web');
    }

    /** The same shared cap EmailLoginTest proves for the email path — the two signup surfaces must draw from one budget, not two. */
    public function test_a_second_phone_signup_from_the_same_ip_in_one_day_is_refused(): void
    {
        Settings::set('limits.register_per_ip_per_day', 1);

        $this->fillProfileStep(Livewire::test(OtpLogin::class), '09121111111', 'first-phone@example.test')
            ->call('completeProfile')
            ->assertRedirect('/portal');

        $second = $this->fillProfileStep(Livewire::test(OtpLogin::class), '09122222222', 'second-phone@example.test')
            ->call('completeProfile');

        $this->assertNotEmpty($second->get('error'), 'a second phone signup over the per-IP daily cap must show an error, not silently succeed or fail');
        $this->assertNotNull(User::where('email', 'first-phone@example.test')->first());
        $this->assertNull(User::where('email', 'second-phone@example.test')->first(), 'a second phone signup from the same IP, over the daily cap, must not create a tenant');
    }

    /** The email and phone signup paths share one register:ip budget, not two independent ones. */
    public function test_a_phone_signup_counts_against_the_same_cap_an_email_signup_would(): void
    {
        Settings::set('limits.register_per_ip_per_day', 1);
        Settings::set('mail.host', 'smtp.example.test');
        Settings::set('mail.from_address', 'bot@example.test');
        \Illuminate\Support\Facades\Mail::fake();

        $this->fillProfileStep(Livewire::test(OtpLogin::class), '09123333333', 'phone-first@example.test')
            ->call('completeProfile')
            ->assertRedirect('/portal');

        $emailAttempt = Livewire::test(\App\Livewire\EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'Email After Phone')
            ->set('email', 'email-after-phone@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('submitRegister');

        $this->assertNotEmpty($emailAttempt->get('error'), 'a phone signup must consume the same daily budget an email signup would, not a separate one');
        $this->assertNull(User::where('email', 'email-after-phone@example.test')->first());
    }
}
