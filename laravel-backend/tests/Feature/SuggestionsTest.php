<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Tenant, Plan};
use App\Services\{TenantService, SuggestionEngine};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * doc-07's actionable suggestions. The properties that matter:
 *
 *  - Rules and thresholds only. A suggestion must be reproducible and
 *    explainable, so there is no generation step to test — the same data
 *    must always produce the same sentence and the same count.
 *  - Every suggestion carries the real conversations it came from, or a
 *    merchant cannot check it and the number is just an assertion.
 *  - A dismissal is permanent. The nightly rebuild recomputes the same
 *    finding with a fresh count, and must not resurrect it.
 *  - Counts are per person, not per message: one customer asking five
 *    times is not five people wanting something.
 */
class SuggestionsTest extends TestCase
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
            'slug' => 'test-' . Str::random(8), 'name' => 'Suggest Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Customer', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        return compact('tenant', 'schema', 'user', 'chatbotId');
    }

    /** One conversation containing one user question. */
    private function ask(array $ctx, string $text, int $daysAgo = 1): string
    {
        $convId = (string) Str::uuid();
        DB::statement("SET search_path TO {$ctx['schema']}, public");
        DB::table('conversations')->insert([
            'id' => $convId, 'chatbot_id' => $ctx['chatbotId'], 'session_id' => 's-' . Str::random(6),
            'status' => 'active', 'started_at' => now()->subDays($daysAgo),
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $convId,
            'chatbot_id' => $ctx['chatbotId'], 'role' => 'user',
            'content' => $text, 'created_at' => now()->subDays($daysAgo),
        ]);
        DB::statement('SET search_path TO public');
        return $convId;
    }

    private function addMessageTo(array $ctx, string $convId, string $text): void
    {
        DB::statement("SET search_path TO {$ctx['schema']}, public");
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $convId,
            'chatbot_id' => $ctx['chatbotId'], 'role' => 'user',
            'content' => $text, 'created_at' => now()->subDay(),
        ]);
        DB::statement('SET search_path TO public');
    }

    private function build(array $ctx): array
    {
        DB::statement("SET search_path TO {$ctx['schema']}, public");
        try {
            return app(SuggestionEngine::class)->build($ctx['chatbotId']);
        } finally {
            DB::statement('SET search_path TO public');
        }
    }

    private function runGenerator(array $ctx): void
    {
        $this->artisan('hamman:generate-suggestions', ['--tenant' => $ctx['schema']])->run();
    }

    private function stored(array $ctx, string $status = 'active'): array
    {
        DB::statement("SET search_path TO {$ctx['schema']}, public");
        $rows = DB::table('suggestions')->where('status', $status)->orderByDesc('count')->get()->all();
        DB::statement('SET search_path TO public');
        return $rows;
    }

    // ── Thresholds ──────────────────────────────────────────────────────

    public function test_a_question_below_the_threshold_produces_nothing(): void
    {
        $ctx = $this->makeTenant();
        // Three people, threshold is four.
        for ($i = 0; $i < 3; $i++) $this->ask($ctx, 'هزینه ارسال به شیراز چقدر است؟');

        $this->assertEmpty($this->build($ctx));
    }

    public function test_the_same_question_from_enough_people_becomes_a_suggestion(): void
    {
        $ctx = $this->makeTenant();
        for ($i = 0; $i < 4; $i++) $this->ask($ctx, 'هزینه ارسال به شیراز چقدر است؟');

        $found = $this->build($ctx);

        $this->assertCount(1, $found);
        $this->assertEquals('topic_gap', $found[0]['type']);
        $this->assertEquals('shipping', $found[0]['params']['topic']);
        $this->assertEquals(4, $found[0]['count']);
    }

    public function test_one_person_asking_repeatedly_is_not_counted_as_many_people(): void
    {
        $ctx = $this->makeTenant();
        $convId = $this->ask($ctx, 'هزینه ارسال به شیراز چقدر است؟');
        // Same customer, same chat, asked again and again.
        for ($i = 0; $i < 6; $i++) $this->addMessageTo($ctx, $convId, 'هزینه ارسال به شیراز چقدر است؟');

        $this->assertEmpty($this->build($ctx), 'Seven messages from one conversation is one person, not seven.');
    }

    public function test_differently_worded_versions_of_one_question_count_together(): void
    {
        $ctx = $this->makeTenant();
        $this->ask($ctx, 'هزینه ارسال به شیراز چقدر است؟');
        $this->ask($ctx, 'هزینه ارسال شیراز چقدره');
        $this->ask($ctx, 'ارسال به شیراز چقدر هزینه دارد');
        $this->ask($ctx, 'هزینه ارسال شیراز');

        $found = $this->build($ctx);

        $this->assertCount(1, $found, 'Four phrasings of one question are one finding.');
        $this->assertEquals(4, $found[0]['count']);
    }

    // ── Evidence ────────────────────────────────────────────────────────

    public function test_every_suggestion_links_to_the_conversations_behind_it(): void
    {
        $ctx = $this->makeTenant();
        $ids = [];
        for ($i = 0; $i < 4; $i++) $ids[] = $this->ask($ctx, 'قیمت طراحی سایت چقدر است؟');

        $found = $this->build($ctx);

        $this->assertNotEmpty($found[0]['conversations']);
        $this->assertEqualsCanonicalizing($ids, $found[0]['conversations']);
    }

    public function test_the_source_conversation_transcript_is_actually_viewable(): void
    {
        $ctx = $this->makeTenant();
        $convId = null;
        for ($i = 0; $i < 4; $i++) $convId = $this->ask($ctx, 'قیمت طراحی سایت چقدر است؟');

        $response = $this->actingAs($ctx['user'], 'web')->get('/portal/conversations?id=' . $convId);

        $response->assertOk();
        $response->assertSee('قیمت طراحی سایت چقدر است؟');
    }

    public function test_a_conversation_id_from_another_tenant_shows_nothing(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $foreignConv = $this->ask($b, 'یک سوال خصوصی برای تننت دیگر');

        $response = $this->actingAs($a['user'], 'web')->get('/portal/conversations?id=' . $foreignConv);

        $response->assertOk();
        $response->assertDontSee('یک سوال خصوصی برای تننت دیگر');
        $response->assertSee(__('suggestions.conversation_not_found'));
    }

    // ── Rules beyond repeated questions ─────────────────────────────────

    public function test_a_product_asked_for_but_not_stocked_becomes_a_suggestion(): void
    {
        $ctx = $this->makeTenant();
        for ($i = 0; $i < 3; $i++) {
            $convId = $this->ask($ctx, 'برند مدیکوب دارید؟');
            DB::statement("SET search_path TO {$ctx['schema']}, public");
            DB::table('conversation_events')->insert([
                'id' => (string) Str::uuid(), 'conversation_id' => $convId, 'chatbot_id' => $ctx['chatbotId'],
                'event_type' => 'sku_lookup',
                'payload' => json_encode(['query' => 'مدیکوب', 'candidate' => null, 'match_type' => 'none']),
                'created_at' => now()->subDay(),
            ]);
            DB::statement('SET search_path TO public');
        }

        $found = collect($this->build($ctx))->firstWhere('type', 'missing_from_catalog');

        $this->assertNotNull($found);
        $this->assertEquals(3, $found['count']);
        $this->assertEquals('مدیکوب', $found['params']['request']);
    }

    public function test_two_products_compared_repeatedly_becomes_a_suggestion(): void
    {
        $ctx = $this->makeTenant();
        for ($i = 0; $i < 3; $i++) {
            $convId = $this->ask($ctx, 'فرق این دو محصول چیست؟');
            DB::statement("SET search_path TO {$ctx['schema']}, public");
            DB::table('conversation_events')->insert([
                'id' => (string) Str::uuid(), 'conversation_id' => $convId, 'chatbot_id' => $ctx['chatbotId'],
                'event_type' => 'tool_called',
                // Reversed on purpose: comparing A,B and B,A is one finding.
                'payload' => json_encode([
                    'tool' => 'compare_products',
                    'arguments' => ['product_ids' => $i % 2 ? [22, 11] : [11, 22]],
                ]),
                'created_at' => now()->subDay(),
            ]);
            DB::statement('SET search_path TO public');
        }

        $found = collect($this->build($ctx))->firstWhere('type', 'compared_pair');

        $this->assertNotNull($found);
        $this->assertEquals(3, $found['count']);
    }

    public function test_an_unanswered_question_asked_by_several_people_becomes_a_suggestion(): void
    {
        $ctx = $this->makeTenant();
        for ($i = 0; $i < 3; $i++) {
            $convId = $this->ask($ctx, 'آیا این محصول اصل است؟');
            DB::statement("SET search_path TO {$ctx['schema']}, public");
            DB::table('conversation_events')->insert([
                'id' => (string) Str::uuid(), 'conversation_id' => $convId, 'chatbot_id' => $ctx['chatbotId'],
                'event_type' => 'unanswered',
                'payload' => json_encode(['query' => 'آیا این محصول اصل است؟', 'reason' => 'below_threshold']),
                'created_at' => now()->subDay(),
            ]);
            DB::statement('SET search_path TO public');
        }

        $found = collect($this->build($ctx))->firstWhere('type', 'unanswered_topic');

        $this->assertNotNull($found);
        $this->assertEquals(3, $found['count']);
    }

    // ── Persistence, dismissal, ordering ────────────────────────────────

    public function test_the_command_stores_suggestions_and_is_safe_to_rerun(): void
    {
        $ctx = $this->makeTenant();
        for ($i = 0; $i < 4; $i++) $this->ask($ctx, 'هزینه ارسال چقدر است؟');

        $this->runGenerator($ctx);
        $this->assertCount(1, $this->stored($ctx));

        // Re-running must refresh, not duplicate.
        $this->runGenerator($ctx);
        $this->assertCount(1, $this->stored($ctx));
    }

    public function test_a_dismissed_suggestion_never_comes_back(): void
    {
        $ctx = $this->makeTenant();
        for ($i = 0; $i < 4; $i++) $this->ask($ctx, 'هزینه ارسال چقدر است؟');
        $this->runGenerator($ctx);

        $id = $this->stored($ctx)[0]->id;
        $this->actingAs($ctx['user'], 'web');
        app(\App\Filament\Customer\Pages\Suggestions::class)->dismiss($id);

        $this->assertEmpty($this->stored($ctx), 'A dismissed suggestion must leave the active list.');

        // More people ask, the count would rise — it still must not return.
        for ($i = 0; $i < 4; $i++) $this->ask($ctx, 'هزینه ارسال چقدر است؟');
        $this->runGenerator($ctx);

        $this->assertEmpty($this->stored($ctx), 'The nightly rebuild must not resurrect a dismissed suggestion.');
        $this->assertCount(1, $this->stored($ctx, 'dismissed'));
    }

    public function test_at_most_five_suggestions_are_shown_ordered_by_count(): void
    {
        $ctx = $this->makeTenant();
        // Seven distinct topics, each over threshold, with different counts.
        $topics = [
            'هزینه ارسال چقدر است؟' => 10,
            'قیمت طراحی سایت چقدر است؟' => 9,
            'شماره تماس شما چیست؟' => 8,
            'گارانتی محصولات چگونه است؟' => 7,
            'روش پرداخت چگونه است؟' => 6,
            'ساعت کاری شما چیست؟' => 5,
            'چه خدماتی ارائه می‌دهید؟' => 4,
        ];
        foreach ($topics as $q => $n) {
            for ($i = 0; $i < $n; $i++) $this->ask($ctx, $q);
        }
        $this->runGenerator($ctx);

        $this->actingAs($ctx['user'], 'web');
        $shown = app(\App\Filament\Customer\Pages\Suggestions::class)->getSuggestions();

        $this->assertCount(SuggestionEngine::MAX_ACTIVE, $shown);
        $counts = array_column($shown, 'count');
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts, 'Suggestions must be ordered by count, highest first.');
        $this->assertEquals(10, $counts[0]);
    }

    public function test_a_finding_that_drops_below_threshold_goes_stale_rather_than_being_deleted(): void
    {
        $ctx = $this->makeTenant();
        for ($i = 0; $i < 4; $i++) $this->ask($ctx, 'هزینه ارسال چقدر است؟', 2);
        $this->runGenerator($ctx);
        $this->assertCount(1, $this->stored($ctx));

        // Age every message out of the 90-day window.
        DB::statement("SET search_path TO {$ctx['schema']}, public");
        DB::table('messages')->update(['created_at' => now()->subDays(200)]);
        DB::statement('SET search_path TO public');

        $this->runGenerator($ctx);

        $this->assertEmpty($this->stored($ctx));
        $this->assertCount(1, $this->stored($ctx, 'stale'), 'Dropping below threshold must not delete the row — that would lose a dismissal.');
    }

    // ── The page itself ─────────────────────────────────────────────────

    public function test_the_page_shows_a_real_suggestion_with_its_number_and_action(): void
    {
        $ctx = $this->makeTenant();
        for ($i = 0; $i < 4; $i++) $this->ask($ctx, 'هزینه ارسال به شیراز چقدر است؟');
        $this->runGenerator($ctx);

        $response = $this->actingAs($ctx['user'], 'web')->get('/portal/suggestions');

        $response->assertOk();
        $response->assertSee(__('suggestions.text_topic_shipping', ['count' => 4]));
        $response->assertSee(__('suggestions.action_topic_shipping'));
    }

    public function test_a_tenant_with_nothing_to_act_on_sees_the_empty_state(): void
    {
        $ctx = $this->makeTenant();

        $response = $this->actingAs($ctx['user'], 'web')->get('/portal/suggestions');

        $response->assertOk();
        $response->assertSee(__('suggestions.empty_title'));
    }

    // ── Explainability: same input, same output, no model ───────────────

    public function test_the_same_data_always_produces_the_same_suggestion(): void
    {
        $ctx = $this->makeTenant();
        for ($i = 0; $i < 4; $i++) $this->ask($ctx, 'هزینه ارسال چقدر است؟');

        $first = $this->build($ctx);
        $second = $this->build($ctx);

        $this->assertEquals($first[0]['fingerprint'], $second[0]['fingerprint']);
        $this->assertEquals($first[0]['count'], $second[0]['count']);
    }

    public function test_topic_classification_is_plain_keyword_matching(): void
    {
        $engine = app(SuggestionEngine::class);

        $this->assertEquals('shipping', $engine->classifyTopic('هزینه ارسال چقدر است؟'));
        $this->assertEquals('price', $engine->classifyTopic('قیمت این محصول چنده'));
        $this->assertEquals('contact', $engine->classifyTopic('شماره تماس شما چیست'));
        // Arabic ك/ي must match the Persian spellings in the rule list.
        $this->assertEquals('hours', $engine->classifyTopic('ساعت كاري شما چيست'));
        $this->assertNull($engine->classifyTopic('سفینه فضایی ناسا چند موتور دارد'));
    }
}
