<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Plan;
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Real reported bug: a chatbot with no welcome_message set (e.g. created
 * directly in the customer portal, never had its WordPress plugin push
 * settings) showed no welcome message at all — ChatController::
 * createSession() read chatbots.welcome_message directly with zero
 * fallback, bypassing WidgetDefaults/widget_config entirely, unlike every
 * other widget string (send_button_label, primary_color, ...) which
 * already merged through App\Support\WidgetDefaults. The *new*-conversation
 * branch was worse still — it returned the raw, completely unmerged
 * widget_config, so even primary_color/position had no fallback there.
 *
 * This also covers the architecture decision that the server (not the
 * WordPress plugin) is now the single source of truth for content
 * settings: chatbots.welcome_message, when set, must still win over the
 * generic default — that's what lets a customer-portal edit propagate to
 * the widget with zero WordPress-side changes.
 */
class WidgetDefaultsFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbot(array $overrides = []): array
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
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert(array_merge([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'welcome_message' => null, 'language' => 'fa',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot', 'primary_domain' => null,
        ]);

        return compact('chatbotId', 'schema', 'tenant');
    }

    public function test_chatbot_with_no_welcome_message_gets_the_bilingual_default(): void
    {
        ['chatbotId' => $chatbotId] = $this->makeChatbot(['welcome_message' => null, 'language' => 'fa']);

        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId, 'session_id' => 'sess-welcome-1',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('data.welcome_message'), 'A chatbot with no welcome_message set must still get a default, not an empty string.');
        $this->assertEquals('سلام! چطور می‌توانم کمکتان کنم؟', $response->json('data.welcome_message'));
    }

    public function test_english_chatbot_with_no_welcome_message_gets_english_default(): void
    {
        ['chatbotId' => $chatbotId] = $this->makeChatbot(['welcome_message' => null, 'language' => 'en']);

        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId, 'session_id' => 'sess-welcome-2',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(201);
        $this->assertEquals('Hi! How can I help you?', $response->json('data.welcome_message'));
    }

    public function test_explicit_welcome_message_always_wins_over_the_default(): void
    {
        ['chatbotId' => $chatbotId] = $this->makeChatbot(['welcome_message' => 'Custom greeting set in the portal', 'language' => 'en']);

        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId, 'session_id' => 'sess-welcome-3',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(201);
        $this->assertEquals('Custom greeting set in the portal', $response->json('data.welcome_message'));
    }

    public function test_returning_visitor_session_also_gets_the_default(): void
    {
        // The $existing-conversation branch is a *separate* code path from
        // the brand-new-conversation one — both had the same bug
        // independently and both must be covered.
        ['chatbotId' => $chatbotId] = $this->makeChatbot(['welcome_message' => null, 'language' => 'en']);

        $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId, 'session_id' => 'sess-welcome-4',
        ], ['Origin' => 'https://example.test'])->assertStatus(201);

        // Same session_id again -> hits the $existing branch.
        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId, 'session_id' => 'sess-welcome-4',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(200);
        $this->assertEquals('Hi! How can I help you?', $response->json('data.welcome_message'));
    }

    public function test_new_conversation_widget_config_is_fully_merged_with_defaults(): void
    {
        // Guards the *other* half of the bug: the new-conversation branch
        // previously returned the completely raw, unmerged widget_config —
        // so even fields with existing defaults (primary_color, position)
        // had none there, only in the $existing-conversation branch.
        ['chatbotId' => $chatbotId] = $this->makeChatbot(['welcome_message' => null, 'language' => 'en']);

        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId, 'session_id' => 'sess-welcome-5',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(201);
        $config = $response->json('data.widget_config');
        $this->assertNotEmpty($config['primary_color'] ?? null);
        $this->assertNotEmpty($config['send_button_label'] ?? null);
        $this->assertEquals('Online Support', $config['chat_title'] ?? null);
        $this->assertEquals('AI Assistant', $config['ai_name'] ?? null);
        $this->assertEquals([], $config['quick_questions'] ?? null);
    }

    public function test_system_prompt_column_wins_as_system_instruction_default(): void
    {
        ['chatbotId' => $chatbotId] = $this->makeChatbot(['system_prompt' => 'Always mention our 30-day return policy.', 'language' => 'en']);

        $response = $this->postJson('/api/v1/chat/session', [
            'chatbot_id' => $chatbotId, 'session_id' => 'sess-welcome-6',
        ], ['Origin' => 'https://example.test']);

        $response->assertStatus(201);
        $this->assertEquals(
            'Always mention our 30-day return policy.',
            $response->json('data.widget_config.system_instruction')
        );
    }
}
