<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Tenant, Plan};
use App\Services\{TenantService, TrendsService};
use App\Support\TextSimilarity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The Trends report. Three things are load-bearing and all three are
 * asserted here rather than assumed:
 *
 *  1. The page must not scan the messages table. That is the one query
 *     that grows without bound, and this page offers a one-year range —
 *     so there is an explicit test that messages is never touched.
 *  2. The whole page must stay under 15 queries at every range.
 *  3. A tenant without enough data must be told so, not shown an empty
 *     chart pretending to be a finding.
 */
class TrendsTest extends TestCase
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
            'slug' => 'test-' . Str::random(8), 'name' => 'Trends Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'), 'password_hash' => bcrypt('irrelevant'),
            'name' => 'Trends Customer', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        $chatbotId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-tr',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        Cache::flush();

        return compact('tenant', 'schema', 'user', 'chatbotId', 'conversationId');
    }

    private function addDay(string $schema, string $chatbotId, string $date, int $userMessages, array $intents = []): void
    {
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('analytics_daily')->insert([
            'chatbot_id' => $chatbotId, 'date' => $date,
            'user_messages' => $userMessages, 'total_messages' => $userMessages * 2,
            'intent_counts' => json_encode($intents),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');
    }

    private function addEvent(string $schema, string $chatbotId, string $convId, string $type, array $payload, string $when): void
    {
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('conversation_events')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $convId, 'chatbot_id' => $chatbotId,
            'event_type' => $type, 'payload' => json_encode($payload), 'created_at' => $when,
        ]);
        DB::statement('SET search_path TO public');
    }

    /** Enough volume that the report is willing to draw conclusions. */
    private function seedBusyTenant(array $ctx): void
    {
        ['schema' => $s, 'chatbotId' => $c, 'conversationId' => $v] = $ctx;

        for ($i = 0; $i < 20; $i++) {
            $this->addDay($s, $c, now()->subDays($i)->toDateString(), 5, ['price' => 3, 'shipping' => 2]);
        }
        // Previous period: shipping was much bigger, price smaller.
        for ($i = 31; $i < 50; $i++) {
            $this->addDay($s, $c, now()->subDays($i)->toDateString(), 4, ['price' => 1, 'shipping' => 6]);
        }
    }

    // ── Performance: the whole point of reading pre-aggregated data ──────

    /**
     * Uses the connection's own query log rather than DB::listen(): a
     * listener registered inside a loop keeps firing for every later
     * iteration, and a `use (&$count)` counter is the same variable each
     * time round, so the "count" silently becomes a multiple of the real
     * one. The log is reset per measurement and cannot drift that way.
     *
     * @return array<int, string> the SQL run during $work
     */
    private function captureQueries(callable $work): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $work();
            return array_column($connection->getQueryLog(), 'query');
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    public function test_the_page_never_touches_the_messages_table(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);

        $queries = $this->captureQueries(fn () => app(TrendsService::class)->get($ctx['schema'], '1y'));

        $touchedMessages = array_filter($queries, fn ($sql) => preg_match('/\bfrom\s+"?messages"?/i', $sql));
        $this->assertEmpty($touchedMessages, 'Trends must read analytics_daily/events only, never scan messages: ' . implode(' | ', $touchedMessages));
    }

    public function test_every_range_stays_under_the_query_budget(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);

        foreach (array_keys(TrendsService::RANGES) as $range) {
            Cache::flush();
            $queries = $this->captureQueries(fn () => app(TrendsService::class)->get($ctx['schema'], $range));

            $this->assertLessThan(
                15,
                count($queries),
                "Range {$range} used " . count($queries) . " queries; the budget is under 15."
            );
        }
    }

    public function test_a_cached_report_costs_no_queries_at_all(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);

        app(TrendsService::class)->get($ctx['schema'], '1y');

        $queries = $this->captureQueries(fn () => app(TrendsService::class)->get($ctx['schema'], '1y'));

        $this->assertCount(0, $queries, 'A one-year range must be served from cache on the second call.');
    }

    // ── Low data is an answer, not an empty chart ────────────────────────

    public function test_a_tenant_with_little_data_is_told_so(): void
    {
        $ctx = $this->makeTenant();
        $this->addDay($ctx['schema'], $ctx['chatbotId'], now()->toDateString(), 3);

        $data = app(TrendsService::class)->get($ctx['schema'], '30d');

        $this->assertFalse($data['has_enough_data']);
        $this->assertEquals(3, $data['user_messages']);
    }

    public function test_a_tenant_with_no_data_at_all_does_not_error(): void
    {
        $ctx = $this->makeTenant();

        $data = app(TrendsService::class)->get($ctx['schema'], '1y');

        $this->assertFalse($data['has_enough_data']);
        $this->assertEquals(0, $data['user_messages']);
        $this->assertSame([], $data['intents']);
        $this->assertFalse($data['seasonal']['available']);
    }

    public function test_the_page_renders_the_low_data_message_not_an_empty_chart(): void
    {
        $ctx = $this->makeTenant();
        $this->addDay($ctx['schema'], $ctx['chatbotId'], now()->toDateString(), 3);

        $response = $this->actingAs($ctx['user'], 'web')->get('/portal/trends');

        $response->assertOk();
        $response->assertSee(__('trends.low_data_title'));
        $response->assertDontSee(__('trends.top_intents'));
    }

    // ── Seasonality gates itself on real history ────────────────────────

    public function test_seasonality_says_it_needs_a_year_when_history_is_short(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);

        $data = app(TrendsService::class)->get($ctx['schema'], '1y');

        $this->assertFalse($data['seasonal']['available']);
        $this->assertLessThan(365, $data['history_days']);
    }

    public function test_seasonality_activates_once_a_year_of_history_exists(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);
        // Last year's same month, plus a marker far enough back to prove a
        // year of history.
        $this->addDay($ctx['schema'], $ctx['chatbotId'], now()->subDays(400)->toDateString(), 7);
        $this->addDay($ctx['schema'], $ctx['chatbotId'], now()->subYear()->startOfMonth()->toDateString(), 11);

        $data = app(TrendsService::class)->get($ctx['schema'], '1y');

        $this->assertTrue($data['seasonal']['available']);
        $this->assertEquals(11, $data['seasonal']['last_year']);
    }

    // ── The actual findings ─────────────────────────────────────────────

    public function test_intents_carry_their_change_against_the_previous_period(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);

        $data = app(TrendsService::class)->get($ctx['schema'], '30d');
        $byIntent = collect($data['intents'])->keyBy('intent');

        // price: 3/day x 20 days = 60 now, 1/day x 19 = 19 before -> up.
        $this->assertEquals(60, $byIntent['price']['count']);
        $this->assertGreaterThan(0, $byIntent['price']['change_pct']);
        // shipping: 2x20 = 40 now, 6x19 = 114 before -> down.
        $this->assertEquals(40, $byIntent['shipping']['count']);
        $this->assertLessThan(0, $byIntent['shipping']['change_pct']);
    }

    public function test_a_brand_new_intent_is_reported_as_new_not_as_a_percentage(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);
        // Day 25 is inside the 30-day window but outside the days
        // seedBusyTenant() already wrote — analytics_daily is unique per
        // (chatbot_id, date), so it has to be a day of its own.
        $this->addDay($ctx['schema'], $ctx['chatbotId'], now()->subDays(25)->toDateString(), 5, ['warranty_new' => 9]);

        $data = app(TrendsService::class)->get($ctx['schema'], '30d');
        $new = collect($data['intents'])->firstWhere('intent', 'warranty_new');

        $this->assertNotNull($new);
        $this->assertNull($new['change_pct'], 'No previous baseline must read as "new", not a made-up percentage.');
    }

    public function test_products_asked_about_are_counted_from_events(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);
        $this->addEvent($ctx['schema'], $ctx['chatbotId'], $ctx['conversationId'], 'product_mentioned', [
            'product_ids' => ['https://shop.test/product/1969', 'https://shop.test/product/1543'],
            'context' => ['Travel Mug', 'Hot Water Bag'],
        ], now()->subDay());

        $data = app(TrendsService::class)->get($ctx['schema'], '30d');

        $titles = array_column($data['asked_products'], 'title');
        $this->assertContains('Travel Mug', $titles);
        // The numeric id is parsed out of the permalink so it can line up
        // with order line items.
        $this->assertEquals('1969', $data['asked_products'][0]['product_id']);
    }

    public function test_the_demand_gap_flags_a_much_asked_but_unsold_product(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);
        $this->addEvent($ctx['schema'], $ctx['chatbotId'], $ctx['conversationId'], 'product_mentioned', [
            'product_ids' => ['https://shop.test/product/500'],
            'context' => ['Popular But Unsold'],
        ], now()->subDay());

        DB::statement("SET search_path TO {$ctx['schema']}, public");
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(), 'chatbot_id' => $ctx['chatbotId'],
            'woo_order_id' => 9001, 'total' => 1000, 'currency' => 'IRT', 'status' => 'completed',
            'line_items' => json_encode([['product_id' => 777, 'quantity' => 5]]),
            'created_at' => now()->subDay(),
        ]);
        DB::statement('SET search_path TO public');

        $data = app(TrendsService::class)->get($ctx['schema'], '30d');

        $gap = collect($data['demand_gap'])->firstWhere('product_id', '500');
        $this->assertNotNull($gap, 'A product asked about but never sold is exactly the gap this panel exists to show.');
        $this->assertNull($gap['sell_rank']);
    }

    public function test_requests_with_no_catalog_match_are_listed_by_count(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);
        foreach ([1, 2, 3] as $n) {
            $this->addEvent($ctx['schema'], $ctx['chatbotId'], $ctx['conversationId'], 'sku_lookup', [
                'query' => 'قمقمه استنلی', 'candidate' => null, 'match_type' => 'none',
            ], now()->subDays($n));
        }
        // A lookup that DID match must never appear in the shopping list.
        $this->addEvent($ctx['schema'], $ctx['chatbotId'], $ctx['conversationId'], 'sku_lookup', [
            'query' => 'قمقمه مربع', 'candidate' => '2249', 'match_type' => 'exact',
        ], now()->subDay());

        $data = app(TrendsService::class)->get($ctx['schema'], '30d');

        $labels = array_column($data['missing_from_catalog'], 'label');
        $this->assertContains('قمقمه استنلی', $labels);
        $this->assertNotContains('قمقمه مربع', $labels);
    }

    public function test_unanswered_questions_are_grouped_by_similarity_not_listed_one_by_one(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);
        // Three phrasings of one question.
        foreach (['هزینه ارسال به شیراز چقدره', 'هزینه ارسال شیراز', 'ارسال به شیراز چقدر هزینه داره'] as $i => $q) {
            $this->addEvent($ctx['schema'], $ctx['chatbotId'], $ctx['conversationId'], 'unanswered', [
                'query' => $q, 'best_score' => 0.1, 'reason' => 'below_threshold',
            ], now()->subDays($i + 1));
        }

        $data = app(TrendsService::class)->get($ctx['schema'], '30d');

        $this->assertCount(1, $data['unanswered_groups'], 'Three phrasings of one question must collapse into one row.');
        $this->assertEquals(3, $data['unanswered_groups'][0]['count']);
    }

    public function test_emerging_and_declining_topics_are_split_by_period(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);

        // Only in the current period.
        $this->addEvent($ctx['schema'], $ctx['chatbotId'], $ctx['conversationId'], 'unanswered',
            ['query' => 'گارانتی تعویض طلایی'], now()->subDays(3));
        // Only in the previous period.
        $this->addEvent($ctx['schema'], $ctx['chatbotId'], $ctx['conversationId'], 'unanswered',
            ['query' => 'تخفیف نوروزی دارید'], now()->subDays(40));

        $data = app(TrendsService::class)->get($ctx['schema'], '30d');

        $this->assertContains('گارانتی تعویض طلایی', array_column($data['emerging_topics'], 'label'));
        $this->assertContains('تخفیف نوروزی دارید', array_column($data['declining_topics'], 'label'));
    }

    // ── Exports ─────────────────────────────────────────────────────────

    public function test_the_print_view_renders_for_an_authenticated_tenant(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);

        $response = $this->actingAs($ctx['user'], 'web')->get('/portal/trends/print?range=3m');

        $response->assertOk();
        $response->assertSee(__('trends.csv_report_title'));
        $response->assertSee('Trends Tenant');
    }

    public function test_the_print_view_rejects_a_guest(): void
    {
        $this->get('/portal/trends/print')->assertRedirect();
    }

    public function test_an_unknown_range_falls_back_instead_of_erroring(): void
    {
        $ctx = $this->makeTenant();
        $this->seedBusyTenant($ctx);

        $response = $this->actingAs($ctx['user'], 'web')->get('/portal/trends/print?range=../../etc/passwd');

        $response->assertOk();
    }

    // ── The similarity helper itself ────────────────────────────────────

    public function test_persian_spelling_variants_normalize_to_the_same_text(): void
    {
        // Arabic ي/ك vs Persian ی/ک, and Arabic-Indic digits.
        $this->assertEquals(
            TextSimilarity::normalize('كيف ٣'),
            TextSimilarity::normalize('کیف 3')
        );
    }

    public function test_stopwords_do_not_make_unrelated_questions_look_similar(): void
    {
        $a = TextSimilarity::tokens('قیمت این محصول چقدر است');
        $b = TextSimilarity::tokens('ارسال به تهران چقدر است');
        $this->assertLessThan(0.5, TextSimilarity::jaccard($a, $b));
    }

    public function test_a_question_that_matches_nothing_stays_in_its_own_group(): void
    {
        $groups = TextSimilarity::group([
            ['text' => 'قیمت قمقمه استنلی', 'count' => 2],
            ['text' => 'ساعت کاری فروشگاه', 'count' => 1],
        ]);
        $this->assertCount(2, $groups);
        $this->assertEquals(2, $groups[0]['count']);
    }
}
