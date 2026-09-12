<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Tenant, Plan};
use App\Models\Tenant\{Chatbot, Conversation, Lead};
use App\Services\{TenantService, LeadCaptureService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The out_of_stock / not_in_catalog lead modes.
 *
 * The difference from the original unanswered mode is that the bot DID
 * answer — it answered "we can't sell you that", which is the moment a
 * shop can still save the sale. Two separate modes rather than one,
 * because "we'll text you when it's restocked" is a promise a shop can
 * keep and the same sentence about something it has never carried is not.
 *
 * The property that actually matters to the merchant is requested_item:
 * a lead saying only "someone wants a callback" is useless without "about
 * what", so it is asserted on every capture path here.
 */
class LeadCaptureItemModesTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbot(array $widgetConfig = []): array
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
        $conversationId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa',
            'widget_config' => json_encode($widgetConfig),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-' . Str::random(6),
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        return compact('tenant', 'schema', 'user', 'chatbotId', 'conversationId');
    }

    /** Runs $fn with the tenant schema active, like a real request would. */
    private function inSchema(array $ctx, callable $fn)
    {
        DB::statement("SET search_path TO {$ctx['schema']}, public");
        try {
            return $fn();
        } finally {
            DB::statement('SET search_path TO public');
        }
    }

    private const BOTH_ON = [
        'lead_capture_enabled' => true,
        'lead_capture_out_of_stock_enabled' => true,
        'lead_capture_not_in_catalog_enabled' => true,
    ];

    // ── The offer is made, and only when it should be ───────────────────

    public function test_an_out_of_stock_product_triggers_the_offer_with_the_item_name(): void
    {
        $ctx = $this->makeChatbot(self::BOTH_ON);

        $prompt = $this->inSchema($ctx, function () use ($ctx) {
            $conv = Conversation::find($ctx['conversationId']);
            $bot  = Chatbot::find($ctx['chatbotId']);
            return app(LeadCaptureService::class)
                ->promptForItem($conv, $bot, 'out_of_stock', 'کیسه آب گرم فضانورد', 'موجوده؟');
        });

        $this->assertNotNull($prompt);
        $this->assertStringContainsString('کیسه آب گرم فضانورد', $prompt, 'The offer must name the product.');
    }

    public function test_a_product_not_in_the_catalog_uses_its_own_wording(): void
    {
        $ctx = $this->makeChatbot(self::BOTH_ON);

        [$outOfStock, $notInCatalog] = $this->inSchema($ctx, function () use ($ctx) {
            $bot = Chatbot::find($ctx['chatbotId']);
            $svc = app(LeadCaptureService::class);
            return [
                $svc->promptForItem(Conversation::find($ctx['conversationId']), $bot, 'out_of_stock', 'مدیکوب', 'q'),
                $svc->promptForItem(Conversation::find($ctx['conversationId']), $bot, 'not_in_catalog', 'مدیکوب', 'q'),
            ];
        });

        $this->assertNotEquals($outOfStock, $notInCatalog,
            'Promising a restock and promising to consider stocking something are different promises.');
    }

    // ── The off switches, which the task called for explicitly ──────────

    public function test_each_mode_can_be_switched_off_independently(): void
    {
        $ctx = $this->makeChatbot([
            'lead_capture_enabled' => true,
            'lead_capture_out_of_stock_enabled' => true,
            'lead_capture_not_in_catalog_enabled' => false,
        ]);

        [$outOfStock, $notInCatalog] = $this->inSchema($ctx, function () use ($ctx) {
            $bot = Chatbot::find($ctx['chatbotId']);
            $svc = app(LeadCaptureService::class);
            return [
                $svc->promptForItem(Conversation::find($ctx['conversationId']), $bot, 'out_of_stock', 'x', 'q'),
                $svc->promptForItem(Conversation::find($ctx['conversationId']), $bot, 'not_in_catalog', 'y', 'q'),
            ];
        });

        $this->assertNotNull($outOfStock);
        $this->assertNull($notInCatalog, 'A mode switched off must not ask for a number.');
    }

    public function test_both_modes_are_off_unless_turned_on(): void
    {
        // Lead capture itself on, product modes never enabled.
        $ctx = $this->makeChatbot(['lead_capture_enabled' => true]);

        $results = $this->inSchema($ctx, function () use ($ctx) {
            $bot = Chatbot::find($ctx['chatbotId']);
            $svc = app(LeadCaptureService::class);
            return [
                $svc->promptForItem(Conversation::find($ctx['conversationId']), $bot, 'out_of_stock', 'x', 'q'),
                $svc->promptForItem(Conversation::find($ctx['conversationId']), $bot, 'not_in_catalog', 'y', 'q'),
            ];
        });

        $this->assertNull($results[0]);
        $this->assertNull($results[1], 'Enabling lead capture must not silently enable the product modes too.');
    }

    public function test_the_modes_do_nothing_when_lead_capture_itself_is_off(): void
    {
        $ctx = $this->makeChatbot([
            'lead_capture_enabled' => false,
            'lead_capture_out_of_stock_enabled' => true,
        ]);

        $prompt = $this->inSchema($ctx, fn () => app(LeadCaptureService::class)->promptForItem(
            Conversation::find($ctx['conversationId']),
            Chatbot::find($ctx['chatbotId']),
            'out_of_stock', 'x', 'q'
        ));

        $this->assertNull($prompt);
    }

    public function test_a_disabled_mode_never_arms_the_next_turn(): void
    {
        $ctx = $this->makeChatbot(['lead_capture_enabled' => true]);

        $this->inSchema($ctx, function () use ($ctx) {
            app(LeadCaptureService::class)->promptForItem(
                Conversation::find($ctx['conversationId']),
                Chatbot::find($ctx['chatbotId']),
                'out_of_stock', 'x', 'q'
            );
            $conv = Conversation::find($ctx['conversationId']);
            $this->assertNull($conv->pending_lead_question,
                'A refused offer must leave the conversation unchanged, or the next message gets eaten as contact info.');
        });
    }

    // ── requested_item survives to the lead row ─────────────────────────

    public function test_the_requested_item_is_stored_on_the_lead_not_just_in_the_question(): void
    {
        $ctx = $this->makeChatbot(self::BOTH_ON);

        $lead = $this->inSchema($ctx, function () use ($ctx) {
            $bot = Chatbot::find($ctx['chatbotId']);
            $svc = app(LeadCaptureService::class);
            $svc->promptForItem(Conversation::find($ctx['conversationId']), $bot, 'out_of_stock', 'کیسه آب گرم فضانورد', 'موجوده؟');

            // Second turn: the customer answers with their number.
            $out = $svc->handleContactAttempt(Conversation::find($ctx['conversationId']), $bot, '09121234567');
            return $out['lead'];
        });

        $this->assertNotNull($lead);
        $this->assertEquals('کیسه آب گرم فضانورد', $lead->requested_item);
        $this->assertEquals('out_of_stock', $lead->type);
        $this->assertEquals('09121234567', $lead->contact);
    }

    public function test_a_not_in_catalog_lead_records_its_own_type(): void
    {
        $ctx = $this->makeChatbot(self::BOTH_ON);

        $lead = $this->inSchema($ctx, function () use ($ctx) {
            $bot = Chatbot::find($ctx['chatbotId']);
            $svc = app(LeadCaptureService::class);
            $svc->promptForItem(Conversation::find($ctx['conversationId']), $bot, 'not_in_catalog', 'مدیکوب', 'برند مدیکوب دارید؟');
            return $svc->handleContactAttempt(Conversation::find($ctx['conversationId']), $bot, '09121234567')['lead'];
        });

        $this->assertEquals('not_in_catalog', $lead->type);
        $this->assertEquals('مدیکوب', $lead->requested_item);
    }

    public function test_the_pending_state_is_cleared_after_capture(): void
    {
        $ctx = $this->makeChatbot(self::BOTH_ON);

        $this->inSchema($ctx, function () use ($ctx) {
            $bot = Chatbot::find($ctx['chatbotId']);
            $svc = app(LeadCaptureService::class);
            $svc->promptForItem(Conversation::find($ctx['conversationId']), $bot, 'out_of_stock', 'x', 'q');
            $svc->handleContactAttempt(Conversation::find($ctx['conversationId']), $bot, '09121234567');

            $conv = Conversation::find($ctx['conversationId']);
            $this->assertNull($conv->pending_lead_question);
            $this->assertNull($conv->pending_lead_type);
            $this->assertNull($conv->pending_lead_item);
        });
    }

    public function test_an_ordinary_unanswered_lead_still_has_no_requested_item(): void
    {
        $ctx = $this->makeChatbot(['lead_capture_enabled' => true]);

        $lead = $this->inSchema($ctx, function () use ($ctx) {
            $bot = Chatbot::find($ctx['chatbotId']);
            $svc = app(LeadCaptureService::class);
            $svc->promptForContact(Conversation::find($ctx['conversationId']), $bot, 'ساعت کاری؟');
            return $svc->handleContactAttempt(Conversation::find($ctx['conversationId']), $bot, '09121234567')['lead'];
        });

        $this->assertEquals('unanswered', $lead->type);
        $this->assertNull($lead->requested_item);
    }

    // ── Merchant-configurable copy ──────────────────────────────────────

    public function test_the_merchant_can_override_the_wording(): void
    {
        $ctx = $this->makeChatbot(array_merge(self::BOTH_ON, [
            'lead_capture_out_of_stock_prompt' => 'برای «:item» خبرت کنم؟',
        ]));

        $prompt = $this->inSchema($ctx, fn () => app(LeadCaptureService::class)->promptForItem(
            Conversation::find($ctx['conversationId']),
            Chatbot::find($ctx['chatbotId']),
            'out_of_stock', 'ماگ', 'q'
        ));

        $this->assertEquals('برای «ماگ» خبرت کنم؟', $prompt);
    }

    // ── The Leads page surfaces them ────────────────────────────────────

    public function test_the_leads_page_shows_the_type_and_requested_item(): void
    {
        $ctx = $this->makeChatbot(self::BOTH_ON);
        $this->inSchema($ctx, function () use ($ctx) {
            Lead::create([
                'conversation_id' => $ctx['conversationId'], 'chatbot_id' => $ctx['chatbotId'],
                'contact' => '09121234567', 'contact_type' => 'phone',
                'question' => 'موجوده؟', 'requested_item' => 'کیسه آب گرم فضانورد',
                'type' => 'out_of_stock', 'status' => 'new',
            ]);
        });

        $response = $this->actingAs($ctx['user'], 'web')->get('/portal/leads');

        $response->assertOk();
        $response->assertSee('کیسه آب گرم فضانورد', false);
        $response->assertSee(__('leads.type_out_of_stock'), false);
    }

    public function test_the_type_filter_narrows_the_list(): void
    {
        $ctx = $this->makeChatbot(self::BOTH_ON);
        $this->inSchema($ctx, function () use ($ctx) {
            foreach ([['out_of_stock', 'ماگ ناموجود'], ['not_in_catalog', 'برند نداشته']] as [$type, $item]) {
                Lead::create([
                    'conversation_id' => $ctx['conversationId'], 'chatbot_id' => $ctx['chatbotId'],
                    'contact' => '0912000' . rand(1000, 9999), 'contact_type' => 'phone',
                    'question' => 'q', 'requested_item' => $item, 'type' => $type, 'status' => 'new',
                ]);
            }
        });

        $page = new \App\Filament\Customer\Pages\Leads();
        $this->actingAs($ctx['user'], 'web');
        $page->setTypeFilter('out_of_stock');
        $leads = $page->getLeads();

        $this->assertCount(1, $leads);
        $this->assertEquals('ماگ ناموجود', $leads[0]->requested_item);
    }
}
