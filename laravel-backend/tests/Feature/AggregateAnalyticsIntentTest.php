<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Plan;
use App\Services\TenantService;
use App\Jobs\AggregateAnalyticsJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * doc-04 "Intent analytics" — AggregateAnalyticsJob must roll up real
 * intent_classified conversation_events (logged by rag_service.py, see
 * intent_classifier.py) into analytics_daily.intent_counts the same way
 * it already rolls up product_mentioned into products_recommended. A
 * malformed/missing intent in a payload must be dropped, never silently
 * miscounted as a fake bucket.
 */
class AggregateAnalyticsIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_intent_classified_events_are_rolled_up_into_intent_counts(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Test Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $date = now()->subDay()->toDateString();

        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-intent-1',
            'status' => 'active', 'started_at' => "{$date} 10:00:00", 'created_at' => "{$date} 10:00:00", 'updated_at' => "{$date} 10:00:00",
        ]);
        // The job's per-chatbot loop only writes an analytics_daily row at
        // all if there's at least one message that day — a real message is
        // needed even though this test is really about the events below.
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $conversationId, 'chatbot_id' => $chatbotId,
            'role' => 'user', 'content' => 'how much does this cost?', 'total_tokens' => 0, 'created_at' => "{$date} 10:00:01",
        ]);

        $intentEvents = ['price', 'price', 'availability', 'authenticity', 'other', null /* malformed */];
        foreach ($intentEvents as $i => $intent) {
            DB::table('conversation_events')->insert([
                'id' => (string) Str::uuid(), 'conversation_id' => $conversationId, 'chatbot_id' => $chatbotId,
                'event_type' => 'intent_classified',
                'payload' => $intent === null ? json_encode(['not_intent' => 'oops']) : json_encode(['intent' => $intent]),
                'created_at' => "{$date} 10:0{$i}:00",
            ]);
        }
        DB::statement('SET search_path TO public');

        (new AggregateAnalyticsJob($date))->handle();

        DB::statement("SET search_path TO {$schema}, public");
        $row = DB::table('analytics_daily')->where('chatbot_id', $chatbotId)->where('date', $date)->first();
        DB::statement('SET search_path TO public');

        $this->assertNotNull($row, 'AggregateAnalyticsJob did not write an analytics_daily row for this chatbot/date.');
        $counts = json_decode($row->intent_counts, true);
        $this->assertEquals(['price' => 2, 'availability' => 1, 'authenticity' => 1, 'other' => 1], $counts);
    }
}
