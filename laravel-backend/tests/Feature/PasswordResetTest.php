<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Mail\ResetPasswordLink;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash, Mail};

/**
 * Password reset, from scratch (2026-10-10) -- see
 * PasswordResetController's own docblock. Mirrors email verification's
 * signed-URL + single-use-nonce shape on purpose, including the SAME
 * plain-text-email escaping lesson (2026-10-09: {!! $body !!}, never
 * {{ $body }}) -- the mailable reuses that exact view rather than a new
 * one that could reintroduce the bug.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('mail.host', 'smtp.test.invalid');
        Settings::set('mail.from_address', 'test@example.test');
    }

    private function makeVerifiedUser(string $email, string $password = 'old-password-123'): User
    {
        return User::create([
            'email' => $email,
            'password' => Hash::make($password),
            'password_hash' => Hash::make($password),
            'name' => 'Reset Test', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
    }

    public function test_the_full_reset_flow_works_end_to_end_with_a_clean_link(): void
    {
        Mail::fake();
        $user = $this->makeVerifiedUser('reset@example.test');

        $this->withSession(['_token' => 'tok'])->post('/forgot-password', [
            '_token' => 'tok', 'email' => 'reset@example.test',
        ])->assertRedirect();

        $this->assertNotNull($user->fresh()->password_reset_nonce);

        $capturedLink = null;
        Mail::assertSent(ResetPasswordLink::class, function (ResetPasswordLink $mail) use (&$capturedLink) {
            $capturedLink = $mail->link;
            // The exact bug this whole feature deliberately avoids: a
            // plain-text email's link must never contain the HTML-escaped
            // "&amp;" in place of "&", or the signature query param gets
            // silently renamed and every real reset link 403s on click.
            return $mail->hasTo('reset@example.test')
                && str_contains($mail->link, '&signature=')
                && !str_contains($mail->link, '&amp;');
        });

        $parts = parse_url($capturedLink);
        $getResponse = $this->get($parts['path'] . '?' . $parts['query']);
        $getResponse->assertOk();
        $getResponse->assertSee(__('auth_email.reset_password_title'));

        $postResponse = $this->post($parts['path'] . '?' . $parts['query'], [
            'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456',
        ]);
        $postResponse->assertRedirect();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('brand-new-password-456', $fresh->password));
        $this->assertTrue(Hash::check('brand-new-password-456', $fresh->password_hash));
        $this->assertNull($fresh->password_reset_nonce, 'a used reset link must be single-use');
        $this->assertAuthenticatedAs($fresh, 'web');
    }

    public function test_the_response_message_does_not_reveal_whether_the_account_exists(): void
    {
        Mail::fake();
        $this->makeVerifiedUser('real@example.test');

        $realResponse = $this->withSession(['_token' => 'tok'])->post('/forgot-password', [
            '_token' => 'tok', 'email' => 'real@example.test',
        ]);
        $fakeResponse = $this->withSession(['_token' => 'tok2'])->post('/forgot-password', [
            '_token' => 'tok2', 'email' => 'nobody-at-all@example.test',
        ]);

        $realResponse->assertSessionHas('landing_status', __('auth_email.reset_link_sent_generic'));
        $fakeResponse->assertSessionHas('landing_status', __('auth_email.reset_link_sent_generic'));

        Mail::assertSentCount(1); // only the real account actually got one
    }

    public function test_an_unverified_email_gets_the_generic_message_but_no_email(): void
    {
        Mail::fake();
        // Still mid-funnel -- e.g. signed up, never clicked the
        // verification link. Nothing has confirmed this address belongs to
        // whoever is asking for a reset, so sendResetLink() must not send
        // one, same gate as createTrialChatbot()'s is_active check.
        User::create([
            'email' => 'unverified@example.test',
            'password' => Hash::make('whatever'), 'password_hash' => Hash::make('whatever'),
            'name' => 'Unverified', 'role' => 'owner', 'email_verified_at' => null,
        ]);

        $response = $this->withSession(['_token' => 'tok'])->post('/forgot-password', [
            '_token' => 'tok', 'email' => 'unverified@example.test',
        ]);

        // Same generic message as a nonexistent address -- revealing
        // "this account exists but is unverified" would be its own leak.
        $response->assertSessionHas('landing_status', __('auth_email.reset_link_sent_generic'));
        Mail::assertNothingSent();
    }

    public function test_a_reset_link_is_single_use(): void
    {
        Mail::fake();
        $user = $this->makeVerifiedUser('onceonly@example.test');

        $this->withSession(['_token' => 'tok'])->post('/forgot-password', [
            '_token' => 'tok', 'email' => 'onceonly@example.test',
        ]);

        $link = null;
        Mail::assertSent(ResetPasswordLink::class, function (ResetPasswordLink $mail) use (&$link) {
            $link = $mail->link;
            return true;
        });
        $parts = parse_url($link);

        $this->post($parts['path'] . '?' . $parts['query'], [
            'password' => 'first-use-password', 'password_confirmation' => 'first-use-password',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('first-use-password', $user->fresh()->password));

        // Same exact link, used again.
        $second = $this->post($parts['path'] . '?' . $parts['query'], [
            'password' => 'second-use-password', 'password_confirmation' => 'second-use-password',
        ]);
        $second->assertRedirect();
        $this->assertTrue(Hash::check('first-use-password', $user->fresh()->password), 'the password must still be whatever the FIRST use set it to');
        $this->assertFalse(Hash::check('second-use-password', $user->fresh()->password));
    }

    public function test_a_second_request_invalidates_the_first_links_nonce(): void
    {
        Mail::fake();
        $user = $this->makeVerifiedUser('tworequests@example.test');

        $this->withSession(['_token' => 'tok1'])->post('/forgot-password', ['_token' => 'tok1', 'email' => 'tworequests@example.test']);
        $firstNonce = $user->fresh()->password_reset_nonce;

        $this->withSession(['_token' => 'tok2'])->post('/forgot-password', ['_token' => 'tok2', 'email' => 'tworequests@example.test']);
        $secondNonce = $user->fresh()->password_reset_nonce;

        $this->assertNotSame($firstNonce, $secondNonce);

        $oldLink = \Illuminate\Support\Facades\URL::temporarySignedRoute('password.reset', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email), 'nonce' => $firstNonce,
        ]);
        $oldParts = parse_url($oldLink);
        $this->get($oldParts['path'] . '?' . $oldParts['query'])->assertRedirect();
        // Still redirected (not a hard error), but the form itself must
        // refuse — confirmed by the password staying unchanged below.
        $this->post($oldParts['path'] . '?' . $oldParts['query'], [
            'password' => 'should-not-apply', 'password_confirmation' => 'should-not-apply',
        ]);
        $this->assertFalse(Hash::check('should-not-apply', $user->fresh()->password));
    }

    public function test_the_request_rate_limit_is_per_email(): void
    {
        Mail::fake();
        Settings::set('limits.password_reset_request_per_hour', 2);
        $this->makeVerifiedUser('ratelimited@example.test');

        $this->withSession(['_token' => 'tok1'])->post('/forgot-password', ['_token' => 'tok1', 'email' => 'ratelimited@example.test']);
        $this->withSession(['_token' => 'tok2'])->post('/forgot-password', ['_token' => 'tok2', 'email' => 'ratelimited@example.test']);
        $third = $this->withSession(['_token' => 'tok3'])->post('/forgot-password', ['_token' => 'tok3', 'email' => 'ratelimited@example.test']);

        $third->assertSessionHas('landing_status', fn ($msg) => str_contains($msg, 'Too many attempts') || str_contains($msg, 'تلاش'));
        Mail::assertSentCount(2);
    }

    public function test_an_expired_reset_link_is_rejected(): void
    {
        $user = $this->makeVerifiedUser('expired@example.test');
        $user->forceFill(['password_reset_nonce' => 'some-nonce'])->save();

        $expiredLink = \Illuminate\Support\Facades\URL::temporarySignedRoute('password.reset', now()->subHour(), [
            'id' => $user->id, 'hash' => sha1($user->email), 'nonce' => 'some-nonce',
        ]);
        $parts = parse_url($expiredLink);

        $this->get($parts['path'] . '?' . $parts['query'])->assertForbidden();
    }

    public function test_a_tampered_reset_link_is_rejected(): void
    {
        $user = $this->makeVerifiedUser('tampered@example.test');
        $user->forceFill(['password_reset_nonce' => 'some-nonce'])->save();

        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute('password.reset', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email), 'nonce' => 'some-nonce',
        ]);
        $tampered = substr($link, 0, -1) . (substr($link, -1) === 'a' ? 'b' : 'a');

        $this->get($tampered)->assertForbidden();
    }
}
