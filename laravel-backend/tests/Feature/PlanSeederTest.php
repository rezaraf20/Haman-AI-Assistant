<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Plan;
use App\Support\Settings;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * PlanSeeder runs on every single deploy (docker-entrypoint.sh's
 * db:seed --force), not just the first install. insertOrIgnore only ever
 * writes these defaults the first time a row's id doesn't exist yet — an
 * admin's real price/limit edit (or a deliberate is_active=false) must
 * never be reverted by a later deploy reseeding (the confirmed, direct
 * cause of wrong prices once reaching the public pricing page).
 *
 * See restructure_plans_for_four_tier_model for the same 4 ids (trial,
 * free, pro, business) on a pre-existing install — this seeder is what a
 * genuinely fresh install gets instead, since migrations (which that
 * restructure runs as) execute before this seeder ever creates a row.
 */
class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_an_empty_database_creates_the_four_tier_plans(): void
    {
        (new PlanSeeder())->run();

        // 4 real tier rows + 1 retired legacy row (old 'enterprise', kept
        // for historical/already-deployed reasons — see PlanSeeder).
        $this->assertEquals(5, Plan::count());
        $trial = Plan::where('slug', 'trial')->first();
        $free = Plan::where('slug', 'free')->first();
        $pro = Plan::where('slug', 'pro')->first();
        $business = Plan::where('slug', 'business')->first();

        $this->assertNotNull($trial);
        $this->assertNotNull($free);
        $this->assertNotNull($pro);
        $this->assertNotNull($business);

        $this->assertFalse($trial->is_public);
        $this->assertFalse($free->is_public);
        $this->assertTrue($pro->is_public);
        $this->assertTrue($business->is_public);

        $this->assertFalse($free->can_purchase_tokens);
        $this->assertTrue($pro->can_purchase_tokens);
        $this->assertContains('search_products', $free->allowed_tools);
        $this->assertNotContains('add_to_cart', $free->allowed_tools, 'free must not get pro-tier tools');
        $this->assertContains('add_to_cart', $pro->allowed_tools);
        $this->assertContains('get_order_status', $business->allowed_tools);
        $this->assertNotContains('get_order_status', $pro->allowed_tools, 'get_order_status is business-only');
    }

    public function test_yearly_price_follows_the_configured_discount_not_a_hardcoded_multiplier(): void
    {
        Settings::set('pricing.annual_discount_months', 3); // "three months free" this time, not the default two

        (new PlanSeeder())->run();

        $pro = Plan::where('slug', 'pro')->first();
        $this->assertEquals($pro->price_monthly * 9, $pro->price_yearly); // 12 - 3 = 9
    }

    /** The exact bug: an admin's price edit must survive the NEXT deploy's reseed, not just the seeder running once. */
    public function test_reseeding_never_reverts_an_admins_price_edit(): void
    {
        (new PlanSeeder())->run();

        $pro = Plan::where('slug', 'pro')->first();
        $pro->update(['price_monthly' => 1490000, 'name' => 'پرو ویژه', 'is_active' => false]);

        (new PlanSeeder())->run();

        $fresh = Plan::where('slug', 'pro')->first();
        $this->assertEquals(1490000, $fresh->price_monthly, 'a real admin-set price must not be reverted by a later deploy reseeding');
        $this->assertEquals('پرو ویژه', $fresh->name);
        $this->assertFalse((bool) $fresh->is_active, 'an admin deliberately disabling a plan must not be silently re-enabled by a reseed either');
    }

    /** Running it three times in a row (matching three real deploys) must not create duplicates either. */
    public function test_reseeding_repeatedly_never_creates_duplicate_plans(): void
    {
        (new PlanSeeder())->run();
        (new PlanSeeder())->run();
        (new PlanSeeder())->run();

        $this->assertEquals(5, Plan::count());
    }
}
