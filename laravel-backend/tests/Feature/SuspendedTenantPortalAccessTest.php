<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Real gap: AuthController::login() already refused a suspended tenant's
 * token API login (tenant->isAccessible() check), but User::canAccessPanel()'s
 * 'customer' branch only ever checked "does this user have a tenant_id at
 * all" — so a suspended tenant's user with a still-valid /portal session
 * cookie kept seeing the full Filament customer panel regardless.
 */
class SuspendedTenantPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithUser(string $status): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Test Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => $status, 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'),
            'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Customer',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        return compact('tenant', 'user');
    }

    public function test_a_suspended_tenants_user_is_locked_out_of_the_customer_portal(): void
    {
        ['user' => $user] = $this->makeTenantWithUser('suspended');

        $response = $this->actingAs($user, 'web')->get('/portal');

        $response->assertForbidden();
        $response->assertSee(__('common.status_suspended'));
    }

    public function test_a_cancelled_tenants_user_is_also_locked_out(): void
    {
        ['user' => $user] = $this->makeTenantWithUser('cancelled');

        $this->actingAs($user, 'web')->get('/portal')->assertForbidden();
    }

    public function test_an_active_tenants_user_reaches_the_customer_portal(): void
    {
        ['user' => $user] = $this->makeTenantWithUser('active');

        $this->actingAs($user, 'web')->get('/portal')->assertOk();
    }

    public function test_a_trial_tenants_user_reaches_the_customer_portal(): void
    {
        ['user' => $user] = $this->makeTenantWithUser('trial');

        $this->actingAs($user, 'web')->get('/portal')->assertOk();
    }

    /** Suspension must take effect on the very next request, same as platform_is_active for admin staff — not just at the next login. */
    public function test_suspending_a_tenant_locks_out_an_already_logged_in_session_immediately(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->makeTenantWithUser('active');

        $this->actingAs($user, 'web')->get('/portal')->assertOk();

        $tenant->update(['status' => 'suspended']);

        $this->actingAs($user->fresh(), 'web')->get('/portal')->assertForbidden();
    }
}
