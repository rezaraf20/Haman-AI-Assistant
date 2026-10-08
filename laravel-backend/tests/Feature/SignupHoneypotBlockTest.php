<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Livewire\EmailLogin;
use App\Models\{Plan, SignupBlock, Tenant, User};
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * The honeypot and the sub-1-second timing signal now REFUSE the signup
 * outright (2026-10-08) instead of only tagging it — both the landing
 * form (non-Livewire) and the customer portal's EmailLogin register flow,
 * sharing SignupRisk::blockReason() so they can never drift apart. A
 * blocked attempt must look, from the caller's side, exactly like a
 * success (same redirect) while creating nothing at all, and must leave
 * exactly one trace: a signup_blocks row with the IP and user agent.
 */
class SignupHoneypotBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TenantService::registerViaEmail() does Plan::where('slug','free')
        // ->firstOrFail() — a fresh test database has no seeder-provided
        // plan, so every real (non-blocked) registration needs one.
        Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 100000, 'is_active' => true, 'sort_order' => 0,
        ]);

        // Without this, LandingSignupController::register()'s mail-usability
        // gate bounces every request before SignupRisk is ever read — same
        // gap that hid the real honeypot behaviour during the earlier
        // manual verification of this exact code path.
        Settings::set('mail.host', 'smtp.test.invalid');
        Settings::set('mail.from_address', 'test@example.test');
    }

    private function csrfTokenFromHomepage(): string
    {
        $html = $this->get('/')->getContent();
        preg_match('/name="_token" value="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1] ?? null, 'the landing page must render a CSRF token to test against');
        return $m[1];
    }

    private function counts(): array
    {
        return ['users' => User::count(), 'tenants' => Tenant::count()];
    }

    // ── Landing form (non-Livewire) ─────────────────────────────────────

    public function test_landing_honeypot_filled_blocks_without_creating_anything(): void
    {
        $token = $this->csrfTokenFromHomepage();
        $before = $this->counts();

        $response = $this->post('/signup', [
            '_token' => $token,
            'name' => 'Bot', 'email' => 'bot-' . time() . '@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'country' => 'IR', 'form_rendered_at' => now()->timestamp - 30,
            'company_fax' => 'http://spam.example', // honeypot filled
        ]);

        // Looks exactly like a real success to whatever submitted it.
        $response->assertRedirect();
        $this->assertSame('http://localhost/portal', $response->headers->get('Location'));

        $this->assertSame($before, $this->counts(), 'a blocked signup must create no user and no tenant');
        $this->assertDatabaseHas('signup_blocks', ['reason' => 'honeypot_filled', 'source' => 'landing']);
    }

    public function test_landing_submitted_under_the_block_threshold_blocks(): void
    {
        $token = $this->csrfTokenFromHomepage();
        $before = $this->counts();

        $response = $this->post('/signup', [
            '_token' => $token,
            'name' => 'Bot', 'email' => 'fast-' . time() . '@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'country' => 'IR', 'form_rendered_at' => now()->timestamp, // 0s: default block threshold is 1.0s
            'company_fax' => '',
        ]);

        $response->assertRedirect();
        $this->assertSame($before, $this->counts(), 'a signup under the block threshold must create nothing');
        $this->assertDatabaseHas('signup_blocks', ['reason' => 'submitted_too_fast', 'source' => 'landing']);
    }

    public function test_landing_logs_the_callers_ip_and_user_agent(): void
    {
        $token = $this->csrfTokenFromHomepage();

        $this->withHeaders(['User-Agent' => 'TestBot/1.0'])->post('/signup', [
            '_token' => $token,
            'name' => 'Bot', 'email' => 'ua-' . time() . '@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'country' => 'IR', 'form_rendered_at' => now()->timestamp - 30,
            'company_fax' => 'filled',
        ]);

        $block = SignupBlock::latest('created_at')->first();
        $this->assertNotNull($block);
        $this->assertSame('TestBot/1.0', $block->user_agent);
        $this->assertNotEmpty($block->ip);
    }

    public function test_landing_submission_between_block_and_tag_thresholds_still_registers_but_is_tagged(): void
    {
        $token = $this->csrfTokenFromHomepage();
        $before = $this->counts();

        // 2s: past the 1.0s block threshold, under the 3.0s tag threshold.
        $response = $this->post('/signup', [
            '_token' => $token,
            'name' => 'Quick Human', 'email' => 'quick-' . time() . '@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'country' => 'IR', 'form_rendered_at' => now()->timestamp - 2,
            'company_fax' => '',
        ]);

        $response->assertRedirect();
        $after = $this->counts();
        $this->assertSame($before['users'] + 1, $after['users'], 'this must still register — it is only tagged, not blocked');

        $tenant = Tenant::latest('id')->first();
        $this->assertTrue($tenant->signup_risk['flags']['submitted_too_fast'] ?? false);
        $this->assertDatabaseMissing('signup_blocks', ['source' => 'landing']);
    }

    public function test_landing_a_normal_submission_still_succeeds(): void
    {
        $token = $this->csrfTokenFromHomepage();
        $before = $this->counts();

        $response = $this->post('/signup', [
            '_token' => $token,
            'name' => 'Real Person', 'email' => 'real-' . time() . '@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'country' => 'IR', 'form_rendered_at' => now()->timestamp - 30,
            'company_fax' => '',
        ]);

        $response->assertRedirect();
        $after = $this->counts();
        $this->assertSame($before['users'] + 1, $after['users']);
        $this->assertSame($before['tenants'] + 1, $after['tenants']);
    }

    // ── Customer portal's EmailLogin (Livewire) — must behave identically ─

    public function test_livewire_honeypot_filled_blocks_without_creating_anything(): void
    {
        $before = $this->counts();

        Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'Bot')
            ->set('email', 'livebot-' . time() . '@example.test')
            ->set('password', 'Password123!')
            ->set('password_confirmation', 'Password123!')
            ->set('mountedAt', now()->timestamp - 30)
            ->set('companyFax', 'http://spam.example')
            ->call('submitRegister')
            ->assertRedirect('/portal');

        $this->assertSame($before, $this->counts(), 'a blocked Livewire signup must create no user and no tenant');
        $this->assertDatabaseHas('signup_blocks', ['reason' => 'honeypot_filled', 'source' => 'portal_email']);
    }

    public function test_livewire_submitted_under_the_block_threshold_blocks(): void
    {
        $before = $this->counts();

        Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'Bot')
            ->set('email', 'livefast-' . time() . '@example.test')
            ->set('password', 'Password123!')
            ->set('password_confirmation', 'Password123!')
            ->set('mountedAt', now()->timestamp) // 0s
            ->set('companyFax', '')
            ->call('submitRegister');

        $this->assertSame($before, $this->counts());
        $this->assertDatabaseHas('signup_blocks', ['reason' => 'submitted_too_fast', 'source' => 'portal_email']);
    }

    public function test_livewire_a_normal_submission_still_succeeds(): void
    {
        $before = $this->counts();

        Livewire::test(EmailLogin::class)
            ->set('mode', 'register')
            ->set('name', 'Real Person')
            ->set('email', 'livereal-' . time() . '@example.test')
            ->set('password', 'Password123!')
            ->set('password_confirmation', 'Password123!')
            ->set('mountedAt', now()->timestamp - 30)
            ->set('companyFax', '')
            ->call('submitRegister');

        $after = $this->counts();
        $this->assertSame($before['users'] + 1, $after['users']);
        $this->assertSame($before['tenants'] + 1, $after['tenants']);
    }
}
