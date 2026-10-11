<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Filament\Resources\TenantResource;
use App\Models\{Plan, Tenant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * trial_ends_at used to be shown in TenantResource's table but had no field
 * in the edit form at all — "give this customer a few more days" was a
 * request only a direct DB write could satisfy. This covers the field
 * added to TenantResource::form() to fix that.
 */
class TenantTrialExtensionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Admin', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $user->platform_role = 'admin';
        $user->platform_is_active = true;
        $user->save();

        return $user;
    }

    private function tenant(): Tenant
    {
        $plan = Plan::create([
            'name' => 'Trial', 'slug' => 'trial-' . Str::random(6), 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 1000, 'is_active' => true, 'sort_order' => 0,
        ]);

        return Tenant::create([
            'slug' => 't-' . Str::random(6), 'name' => 'Customer',
            'email' => Str::random(8) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'trial',
            'trial_ends_at' => now()->addDay(),
        ]);
    }

    public function test_an_admin_can_push_trial_ends_at_forward(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->admin(), 'web');

        $newEnd = now()->addDays(14)->startOfMinute();

        Livewire::test(TenantResource\Pages\EditTenant::class, ['record' => $tenant->getRouteKey()])
            ->fillForm(['trial_ends_at' => $newEnd])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($tenant->fresh()->trial_ends_at->equalTo($newEnd));
    }

    public function test_an_admin_can_shorten_trial_ends_at(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->admin(), 'web');

        $newEnd = now()->subMinute()->startOfMinute();

        Livewire::test(TenantResource\Pages\EditTenant::class, ['record' => $tenant->getRouteKey()])
            ->fillForm(['trial_ends_at' => $newEnd])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($tenant->fresh()->trial_ends_at->equalTo($newEnd));
    }
}
