<?php
namespace Tests\Feature;

use App\Models\{Tenant, User};
use App\Services\TenantService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The resource-allocation gate, and its kill switch
 * (signup.require_email_verification, default OFF — see SettingsRegistry's
 * own docblock: left off until a real test send is confirmed delivered,
 * per the 2026-10-07 email-status report).
 *
 * OFF (default, and every test below unless it says otherwise): the exact
 * pre-gate behaviour — registerViaEmail() creates the schema/chatbot/
 * chatbot_index/API key immediately, chatbot inactive until verification.
 * ON: registerViaEmail() creates only the tenant and user rows; everything
 * else waits for provisionVerifiedTenant(), called once email_verified_at
 * is actually set — which is what makes PurgeUnverifiedSignupsCommand's
 * hard delete safe under that mode: an abandoned signup has nothing to
 * orphan.
 */
class SignupProvisioningGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // registerViaEmail() looks up Plan::where('slug', 'free') directly.
        (new \Database\Seeders\PlanSeeder())->run();
    }

    private function register(): array
    {
        return app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'jane-' . uniqid() . '@example.test',
            'password' => 'irrelevant123', 'country' => 'IR',
        ]);
    }

    // ── Gate OFF (default) — must match the platform's actual current behaviour ──

    public function test_gate_off_by_default(): void
    {
        $this->assertFalse(Settings::get('signup.require_email_verification'));
    }

    public function test_gate_off_provisions_immediately_with_an_inactive_chatbot(): void
    {
        ['tenant' => $tenant] = $this->register();
        $tenant->refresh();

        $this->assertNotNull($tenant->provisioned_at);

        $schemaExists = DB::selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$tenant->schema_name]);
        $this->assertNotNull($schemaExists, 'Gate off must behave exactly like signup always did: the schema exists right away.');

        $entry = DB::table('chatbot_index')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($entry);
        $this->assertFalse((bool) $entry->is_active, 'Inactive until verified — this was never about skipping that check.');
        $this->assertSame('pending_verification', $entry->disabled_reason);
        $this->assertSame(1, DB::table('api_keys')->where('tenant_id', $tenant->id)->count());
    }

    public function test_gate_off_activates_the_chatbot_once_verified(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->register();
        $user->forceFill(['email_verified_at' => now()])->save();

        $service = app(TenantService::class);
        $service->provisionVerifiedTenant($tenant->refresh(), $user);
        $service->activateChatbotsPendingVerification($tenant);

        $entry = DB::table('chatbot_index')->where('tenant_id', $tenant->id)->first();
        $this->assertTrue((bool) $entry->is_active);
        $this->assertNull($entry->disabled_reason);
    }

    // ── Gate ON — the deferred-provisioning behaviour ──

    public function test_gate_on_registering_via_email_creates_nothing_but_the_tenant_and_user_rows(): void
    {
        Settings::set('signup.require_email_verification', true);

        ['tenant' => $tenant] = $this->register();

        $this->assertNull($tenant->provisioned_at);

        $schemaExists = DB::selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$tenant->schema_name]);
        $this->assertNull($schemaExists, 'An unverified signup must not have a real Postgres schema yet.');

        $this->assertSame(0, DB::table('chatbot_index')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('api_keys')->where('tenant_id', $tenant->id)->count());
    }

    public function test_gate_on_provision_verified_tenant_creates_the_schema_chatbot_index_and_api_key_together(): void
    {
        Settings::set('signup.require_email_verification', true);

        ['tenant' => $tenant, 'user' => $user] = $this->register();
        $user->forceFill(['email_verified_at' => now()])->save();

        app(TenantService::class)->provisionVerifiedTenant($tenant, $user);
        $tenant->refresh();

        $this->assertNotNull($tenant->provisioned_at);

        $schemaExists = DB::selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$tenant->schema_name]);
        $this->assertNotNull($schemaExists);

        $entry = DB::table('chatbot_index')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($entry);
        $this->assertTrue((bool) $entry->is_active);
        $this->assertSame(1, DB::table('api_keys')->where('tenant_id', $tenant->id)->count());
    }

    public function test_gate_on_provision_verified_tenant_is_idempotent(): void
    {
        Settings::set('signup.require_email_verification', true);

        ['tenant' => $tenant, 'user' => $user] = $this->register();
        $user->forceFill(['email_verified_at' => now()])->save();

        app(TenantService::class)->provisionVerifiedTenant($tenant, $user);
        app(TenantService::class)->provisionVerifiedTenant($tenant->refresh(), $user);

        // A double-click on an old verification link must not create a
        // second chatbot for the same tenant.
        $this->assertSame(1, DB::table('chatbot_index')->where('tenant_id', $tenant->id)->count());
    }

    // ── Purge command — only meaningful under gate ON; under the OFF
    // default, provisioned_at is set at registration for every signup, so
    // this command naturally never finds anything to purge, with no
    // special-casing needed anywhere.

    public function test_an_unverified_signup_past_the_grace_window_is_hard_deleted_and_logged(): void
    {
        Settings::set('signup.require_email_verification', true);

        ['tenant' => $tenant] = $this->register();
        $tenant->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('haman:purge-unverified-signups')->assertExitCode(0);

        $this->assertNull(Tenant::withTrashed()->find($tenant->id), 'A tenant with no schema must be hard-deleted, not soft-deleted.');
        $this->assertNull(User::withTrashed()->where('tenant_id', $tenant->id)->first());

        $logged = DB::table('platform_activity_log')
            ->where('action', 'unverified_signup_purged')
            ->where('tenant_id', $tenant->id)
            ->first();
        $this->assertNotNull($logged);
        $this->assertSame('system', $logged->user_email);
    }

    public function test_a_recently_registered_unverified_signup_is_left_alone(): void
    {
        Settings::set('signup.require_email_verification', true);

        ['tenant' => $tenant] = $this->register();

        $this->artisan('haman:purge-unverified-signups')->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_a_verified_tenant_is_never_purged_regardless_of_age(): void
    {
        Settings::set('signup.require_email_verification', true);

        ['tenant' => $tenant, 'user' => $user] = $this->register();
        $user->forceFill(['email_verified_at' => now()])->save();
        app(TenantService::class)->provisionVerifiedTenant($tenant, $user);
        $tenant->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('haman:purge-unverified-signups')->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_gate_off_default_means_purge_command_never_finds_a_normal_signup_to_remove(): void
    {
        ['tenant' => $tenant] = $this->register();
        $tenant->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('haman:purge-unverified-signups')->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id), 'Gate off provisions at registration, so provisioned_at is never null for the purge command to act on.');
    }
}
