<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Tenant, Plan};
use App\Services\{TenantService, TrendsService};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The "most compared" report.
 *
 * The count alone is mildly interesting; the win rate is the part a
 * merchant cannot get anywhere else, because it says which of two
 * substitutes is actually winning the decision — and therefore which
 * product page, price or photo set is losing it.
 *
 * "Won" means a cart signal for that product, later in the SAME
 * conversation than the comparison. Nothing here infers a purchase.
 */
class ComparedPairsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Shop',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Owner', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Enough traffic that the report is willing to draw conclusions.
        for ($i = 0; $i < 20; $i++) {
            DB::table('analytics_daily')->insert([
                'chatbot_id' => $chatbotId, 'date' => now()->subDays($i)->toDateString(),
                'user_messages' => 5, 'total_messages' => 10, 'intent_counts' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::statement('SET search_path TO public');
        Cache::flush();

        return compact('tenant', 'schema', 'user', 'chatbotId');
    }

    private function conversation(array $ctx): string
    {
        $id = (string) Str::uuid();
        DB::statement("SET search_path TO {$ctx['schema']}, public");
        DB::table('conversations')->insert([
            'id' => $id, 'chatbot_id' => $ctx['chatbotId'], 'session_id' => 's-' . Str::random(6),
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');
        return $id;
    }

    private function event(array $ctx, string $conv, string $type, array $payload, int $minutesAgo): void
    {
        DB::statement("SET search_path TO {$ctx['schema']}, public");
        DB::table('conversation_events')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $conv, 'chatbot_id' => $ctx['chatbotId'],
            'event_type' => $type, 'payload' => json_encode($payload),
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
        DB::statement('SET search_path TO public');
    }

    private function trends(array $ctx): array
    {
        Cache::flush();
        return app(TrendsService::class)->get($ctx['schema'], '30d');
    }

    // ── Counting ────────────────────────────────────────────────────────

    public function test_a_repeated_matchup_is_counted_once_per_comparison(): void
    {
        $ctx = $this->makeTenant();
        foreach ([60, 50, 40] as $i => $minutes) {
            $conv = $this->conversation($ctx);
            $this->event($ctx, $conv, 'compared_pair', [
                'product_ids' => [10, 20], 'names' => ['ماگ آبی', 'ماگ قرمز'],
            ], $minutes);
        }

        $pairs = $this->trends($ctx)['compared_pairs'];

        $this->assertCount(1, $pairs);
        $this->assertEquals(3, $pairs[0]['count']);
        $this->assertEquals(['ماگ آبی', 'ماگ قرمز'], $pairs[0]['names']);
    }

    public function test_explicit_comparisons_and_co_presentation_share_a_row_but_are_distinguished(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);
        $this->event($ctx, $conv, 'co_presented', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 50);

        $pairs = $this->trends($ctx)['compared_pairs'];

        $this->assertCount(1, $pairs);
        $this->assertEquals(2, $pairs[0]['count']);
        $this->assertEquals(1, $pairs[0]['explicit'], 'Only one of the two was the customer actually asking.');
    }

    public function test_pairs_are_ordered_by_how_often_they_come_up(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);
        foreach ([50, 40, 30] as $m) {
            $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [30, 40], 'names' => ['C', 'D']], $m);
        }

        $pairs = $this->trends($ctx)['compared_pairs'];

        $this->assertEquals(['C', 'D'], $pairs[0]['names']);
        $this->assertEquals(3, $pairs[0]['count']);
    }

    // ── The winner, which is the point ──────────────────────────────────

    public function test_the_product_added_after_the_comparison_wins_the_round(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);
        $this->event($ctx, $conv, 'cart_add_succeeded', ['product_id' => 20], 50);

        $pair = $this->trends($ctx)['compared_pairs'][0];

        $this->assertEquals(1, $pair['winner'], 'B (index 1) was the one added.');
        $this->assertEquals([0, 1], $pair['wins']);
    }

    public function test_a_cart_add_BEFORE_the_comparison_does_not_count(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        // Added first, compared afterwards — the comparison did not cause it.
        $this->event($ctx, $conv, 'cart_add_succeeded', ['product_id' => 20], 60);
        $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 50);

        $pair = $this->trends($ctx)['compared_pairs'][0];

        $this->assertNull($pair['winner']);
        $this->assertEquals(0, $pair['decided']);
    }

    public function test_a_cart_add_in_a_different_conversation_does_not_count(): void
    {
        $ctx = $this->makeTenant();
        $a = $this->conversation($ctx);
        $b = $this->conversation($ctx);
        $this->event($ctx, $a, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);
        $this->event($ctx, $b, 'cart_add_succeeded', ['product_id' => 20], 50);

        $this->assertNull($this->trends($ctx)['compared_pairs'][0]['winner']);
    }

    public function test_a_comparison_nobody_acted_on_reports_not_decided_rather_than_zero(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);

        $pair = $this->trends($ctx)['compared_pairs'][0];

        // "We don't know" and "nobody wanted it" are different answers.
        $this->assertNull($pair['winner']);
        $this->assertEquals(0, $pair['decided']);
    }

    public function test_an_even_split_is_reported_as_a_tie(): void
    {
        $ctx = $this->makeTenant();
        $c1 = $this->conversation($ctx);
        $this->event($ctx, $c1, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);
        $this->event($ctx, $c1, 'cart_add_succeeded', ['product_id' => 10], 50);

        $c2 = $this->conversation($ctx);
        $this->event($ctx, $c2, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 40);
        $this->event($ctx, $c2, 'cart_add_succeeded', ['product_id' => 20], 30);

        $pair = $this->trends($ctx)['compared_pairs'][0];

        $this->assertEquals('tie', $pair['winner']);
        $this->assertEquals([1, 1], $pair['wins']);
    }

    public function test_a_cart_link_counts_as_a_signal_too(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);
        $this->event($ctx, $conv, 'cart_link_generated', ['product_id' => 10], 50);

        $this->assertEquals(0, $this->trends($ctx)['compared_pairs'][0]['winner']);
    }

    public function test_the_earliest_cart_signal_after_the_comparison_decides_it(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);
        $this->event($ctx, $conv, 'cart_add_succeeded', ['product_id' => 10], 50); // first
        $this->event($ctx, $conv, 'cart_add_succeeded', ['product_id' => 20], 40); // changed their mind

        $pair = $this->trends($ctx)['compared_pairs'][0];

        $this->assertEquals(0, $pair['winner'], 'The first choice after the comparison is the one it decided.');
    }

    public function test_a_cart_add_for_an_unrelated_product_decides_nothing(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);
        $this->event($ctx, $conv, 'cart_add_succeeded', ['product_id' => 99], 50);

        $this->assertNull($this->trends($ctx)['compared_pairs'][0]['winner']);
    }

    // ── Budget and page ─────────────────────────────────────────────────

    public function test_the_extra_queries_stay_inside_the_page_budget(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        $this->event($ctx, $conv, 'compared_pair', ['product_ids' => [10, 20], 'names' => ['A', 'B']], 60);

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            Cache::flush();
            app(TrendsService::class)->get($ctx['schema'], '1y');
            $count = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $this->assertLessThan(15, $count, "Trends used {$count} queries; the budget is under 15.");
    }

    public function test_the_section_renders_on_the_page(): void
    {
        $ctx = $this->makeTenant();
        $conv = $this->conversation($ctx);
        $this->event($ctx, $conv, 'compared_pair', [
            'product_ids' => [10, 20], 'names' => ['ماگ آبی', 'ماگ قرمز'],
        ], 60);
        $this->event($ctx, $conv, 'cart_add_succeeded', ['product_id' => 20], 50);

        $response = $this->actingAs($ctx['user'], 'web')->get('/portal/trends');

        $response->assertOk();
        $response->assertSee(__('trends.compared'), false);
        $response->assertSee('ماگ آبی', false);
        $response->assertSee('ماگ قرمز', false);
    }
}
