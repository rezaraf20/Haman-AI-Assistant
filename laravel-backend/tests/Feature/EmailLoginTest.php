<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Livewire\EmailLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_registering_via_email_creates_tenant_and_logs_in(): void
    {
        Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'Jane Doe')
            ->set('email', 'jane@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('submitRegister')
            ->assertRedirect('/portal');

        $user = User::where('email', 'jane@example.test')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->tenant_id);
        $this->assertEquals('Jane', $user->first_name);
        $this->assertEquals('Doe', $user->last_name);
        $this->assertAuthenticatedAs($user, 'web');
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
