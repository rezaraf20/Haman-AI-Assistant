<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * PlanSeeder runs on every single deploy (docker-entrypoint.sh's
 * db:seed --force), not just the first install. It used to updateOrCreate()
 * unconditionally, overwriting price_monthly/price_yearly/name/slug/every
 * max_* limit/features/sort_order/is_active back to the seed placeholders
 * on every run — silently reverting any admin's real price edit the moment
 * the next deploy happened. That was the confirmed, direct cause of wrong
 * prices reaching the public pricing page (2026-09-30 audit).
 */
class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_an_empty_database_creates_the_four_default_plans(): void
    {
        (new PlanSeeder())->run();

        $this->assertEquals(4, Plan::count());
        $this->assertNotNull(Plan::where('slug', 'free')->first());
        $this->assertNotNull(Plan::where('slug', 'starter')->first());
        $this->assertNotNull(Plan::where('slug', 'growth')->first());
        $this->assertNotNull(Plan::where('slug', 'enterprise')->first());
    }

    /** The exact bug: an admin's price edit must survive the NEXT deploy's reseed, not just the seeder running once. */
    public function test_reseeding_never_reverts_an_admins_price_edit(): void
    {
        (new PlanSeeder())->run();

        $starter = Plan::where('slug', 'starter')->first();
        $starter->update(['price_monthly' => 490000, 'name' => 'استارتر', 'is_active' => false]);

        (new PlanSeeder())->run();

        $fresh = Plan::where('slug', 'starter')->first();
        $this->assertEquals(490000, $fresh->price_monthly, 'a real admin-set price must not be reverted by a later deploy reseeding');
        $this->assertEquals('استارتر', $fresh->name);
        $this->assertFalse((bool) $fresh->is_active, 'an admin deliberately disabling a plan must not be silently re-enabled by a reseed either');
    }

    /** Running it three times in a row (matching three real deploys) must not create duplicates either. */
    public function test_reseeding_repeatedly_never_creates_duplicate_plans(): void
    {
        (new PlanSeeder())->run();
        (new PlanSeeder())->run();
        (new PlanSeeder())->run();

        $this->assertEquals(4, Plan::count());
    }
}
