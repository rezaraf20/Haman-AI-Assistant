<?php
namespace Tests\Feature;

use App\Models\{Tenant, User};
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The resource-allocation gate: registerViaEmail() must create ONLY a
 * tenant row and a user row — no schema, no chatbot, no chatbot_index row,
 * no API key — until provisionVerifiedTenant() actually runs, which only
 * happens once email_verified_at is set. This is what makes
 * PurgeUnverifiedSignupsCommand's hard delete safe: an abandoned signup
 * has nothing to orphan.
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

    public function test_registering_via_email_creates_nothing_but_the_tenant_and_user_rows(): void
    {
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'jane-' . uniqid() . '@example.test',
            'password' => 'irrelevant123', 'country' => 'IR',
        ]);
        $tenant = $result['tenant'];

        $this->assertNull($tenant->provisioned_at);

        $schemaExists = DB::selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$tenant->schema_name]);
        $this->assertNull($schemaExists, 'An unverified signup must not have a real Postgres schema yet.');

        $this->assertSame(0, DB::table('chatbot_index')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('api_keys')->where('tenant_id', $tenant->id)->count());
    }

    public function test_provision_verified_tenant_creates_the_schema_chatbot_index_and_api_key_together(): void
    {
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'jane-' . uniqid() . '@example.test',
            'password' => 'irrelevant123', 'country' => 'IR',
        ]);
        $tenant = $result['tenant'];
        $user = $result['user'];
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

    public function test_provision_verified_tenant_is_idempotent(): void
    {
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'jane-' . uniqid() . '@example.test',
            'password' => 'irrelevant123', 'country' => 'IR',
        ]);
        $tenant = $result['tenant'];
        $user = $result['user']->forceFill(['email_verified_at' => now()]);
        $user->save();

        app(TenantService::class)->provisionVerifiedTenant($tenant, $user);
        app(TenantService::class)->provisionVerifiedTenant($tenant->refresh(), $user);

        // A double-click on an old verification link must not create a
        // second chatbot for the same tenant.
        $this->assertSame(1, DB::table('chatbot_index')->where('tenant_id', $tenant->id)->count());
    }

    public function test_an_unverified_signup_past_the_grace_window_is_hard_deleted_and_logged(): void
    {
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'jane-' . uniqid() . '@example.test',
            'password' => 'irrelevant123', 'country' => 'IR',
        ]);
        $tenant = $result['tenant'];
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
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'jane-' . uniqid() . '@example.test',
            'password' => 'irrelevant123', 'country' => 'IR',
        ]);
        $tenant = $result['tenant'];

        $this->artisan('haman:purge-unverified-signups')->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_a_verified_tenant_is_never_purged_regardless_of_age(): void
    {
        $result = app(TenantService::class)->registerViaEmail([
            'name' => 'Jane Doe', 'email' => 'jane-' . uniqid() . '@example.test',
            'password' => 'irrelevant123', 'country' => 'IR',
        ]);
        $tenant = $result['tenant'];
        $user = $result['user']->forceFill(['email_verified_at' => now()]);
        $user->save();
        app(TenantService::class)->provisionVerifiedTenant($tenant, $user);
        $tenant->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('haman:purge-unverified-signups')->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }
}
