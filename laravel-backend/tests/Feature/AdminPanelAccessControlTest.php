<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Regression test for a real production vulnerability: User::canAccessPanel()
 * granted /admin (full cross-tenant visibility) to anyone with role='owner'
 * — but every tenant's own first user gets that role at signup (see
 * TenantService), so any paying customer's account could log into the
 * platform's own admin panel. Fixed by gating on a separate
 * is_platform_admin column instead, false by default and never set by any
 * user-creation code path.
 */
class AdminPanelAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_role_is_refused_admin_panel_access(): void
    {
        // role='owner' with no is_platform_admin — exactly what
        // TenantService::createTenant() produces for every new customer's
        // first user. This must NOT be enough to reach /admin.
        $tenantOwner = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'),
            'password_hash' => bcrypt('irrelevant'),
            'name' => 'Tenant Owner',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($tenantOwner, 'web')->get('/admin');
        $response->assertForbidden();
    }

    public function test_platform_admin_flag_is_granted_admin_panel_access(): void
    {
        $platformAdmin = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'),
            'password_hash' => bcrypt('irrelevant'),
            'name' => 'Platform Admin',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);
        // Not mass-assignable (deliberately absent from User::$fillable) —
        // set directly, the same way the real one-off migration does.
        $platformAdmin->is_platform_admin = true;
        $platformAdmin->save();

        $response = $this->actingAs($platformAdmin, 'web')->get('/admin');
        $response->assertOk();
    }
}
