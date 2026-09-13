<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Tenant, Plan};
use App\Models\Tenant\{Chatbot, Lead, Product};
use App\Services\{TenantService, SyncService, NotificationService, SuggestionEngine};
use App\Filament\Customer\Pages\ProductRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The requests page and the restock notification.
 *
 * The rule that matters most is the one about who gets messaged: when
 * stock returns, the MERCHANT is told and handed the numbers. The people
 * waiting are never texted automatically — that is the shop's decision
 * and the shop's SMS bill, and a bot that texts a queue of strangers the
 * moment a stock field flips is how a sender number gets blocked.
 */
class ProductRequestsTest extends TestCase
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
            'is_active' => true, 'language' => 'fa',
            'notification_settings' => json_encode([
                'telegram' => ['enabled' => true, 'events' => ['restock_waitlist'], 'bot_token' => 'T', 'chat_id' => '1'],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        return compact('tenant', 'schema', 'user', 'chatbotId');
    }

    private function inSchema(array $ctx, callable $fn)
    {
        DB::statement("SET search_path TO {$ctx['schema']}, public");
        try { return $fn(); } finally { DB::statement('SET search_path TO public'); }
    }

    /** One person asking for something, as the lead modes would record it. */
    private function request(array $ctx, string $type, string $item, string $contact, ?int $productId = null): void
    {
        $this->inSchema($ctx, function () use ($ctx, $type, $item, $contact, $productId) {
            $convId = (string) Str::uuid();
            DB::table('conversations')->insert([
                'id' => $convId, 'chatbot_id' => $ctx['chatbotId'], 'session_id' => 's-' . Str::random(6),
                'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Lead::create([
                'conversation_id' => $convId, 'chatbot_id' => $ctx['chatbotId'],
                'contact' => $contact, 'contact_type' => 'phone',
                'question' => 'موجوده؟', 'requested_item' => $item,
                'requested_product_id' => $productId, 'type' => $type,
                'request_status' => 'open', 'status' => 'new',
            ]);
        });
    }

    private function page(array $ctx): ProductRequests
    {
        $this->actingAs($ctx['user'], 'web');
        return new ProductRequests();
    }

    // ── Grouping ────────────────────────────────────────────────────────

    public function test_three_requests_for_one_out_of_stock_product_become_one_row(): void
    {
        $ctx = $this->makeTenant();
        $this->request($ctx, 'out_of_stock', 'کیسه آب گرم فضانورد', '09120000001', 11);
        $this->request($ctx, 'out_of_stock', 'کیسه آب گرم فضانورد', '09120000002', 11);
        $this->request($ctx, 'out_of_stock', 'کیسه آب گرم فضانورد', '09120000003', 11);

        $groups = $this->page($ctx)->getGroups();

        $this->assertCount(1, $groups['waiting'], 'Three people wanting one product is one row, not three.');
        $this->assertEquals(3, $groups['waiting'][0]['people']);
        $this->assertCount(3, $groups['waiting'][0]['contacts']);
        $this->assertEquals('کیسه آب گرم فضانورد', $groups['waiting'][0]['item']);
    }

    public function test_the_same_product_under_different_names_still_groups_by_its_id(): void
    {
        $ctx = $this->makeTenant();
        // The bot recorded slightly different names on different turns.
        $this->request($ctx, 'out_of_stock', 'کیسه آب گرم فضانورد', '09120000001', 11);
        $this->request($ctx, 'out_of_stock', 'کیسه آب گرم', '09120000002', 11);

        $groups = $this->page($ctx)->getGroups();

        $this->assertCount(1, $groups['waiting'], 'A known product id must win over the spelling of its name.');
        $this->assertEquals(2, $groups['waiting'][0]['people']);
    }

    public function test_catalog_requests_group_by_similarity_when_there_is_no_id(): void
    {
        $ctx = $this->makeTenant();
        $this->request($ctx, 'not_in_catalog', 'برند مدیکوب', '09120000001');
        $this->request($ctx, 'not_in_catalog', 'مدیکوب', '09120000002');

        $groups = $this->page($ctx)->getGroups();

        $this->assertCount(1, $groups['missing']);
        $this->assertEquals(2, $groups['missing'][0]['people']);
    }

    public function test_the_two_sections_stay_separate(): void
    {
        $ctx = $this->makeTenant();
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120000001', 10);
        $this->request($ctx, 'not_in_catalog', 'مدیکوب', '09120000002');

        $groups = $this->page($ctx)->getGroups();

        $this->assertCount(1, $groups['waiting']);
        $this->assertCount(1, $groups['missing']);
    }

    public function test_rows_are_ordered_by_how_many_people_asked(): void
    {
        $ctx = $this->makeTenant();
        $this->request($ctx, 'not_in_catalog', 'کم‌طرفدار', '09120000001');
        foreach (range(1, 4) as $i) {
            $this->request($ctx, 'not_in_catalog', 'پرطرفدار', '0912000100' . $i);
        }

        $groups = $this->page($ctx)->getGroups();

        $this->assertEquals('پرطرفدار', $groups['missing'][0]['item']);
        $this->assertEquals(4, $groups['missing'][0]['people']);
    }

    // ── Status ──────────────────────────────────────────────────────────

    public function test_a_group_can_be_marked_fulfilled_and_leaves_the_open_list(): void
    {
        $ctx = $this->makeTenant();
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120000001', 10);
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120000002', 10);

        $page = $this->page($ctx);
        $key = $page->getGroups()['waiting'][0]['key'];
        $page->markGroup($key, 'fulfilled');

        $this->assertEmpty($page->getGroups()['waiting'], 'Fulfilled requests leave the default open view.');

        $page->setStatusFilter('fulfilled');
        $this->assertCount(1, $page->getGroups()['waiting']);
        $this->assertEquals(2, $page->getGroups()['waiting'][0]['people'], 'Marking a group marks everyone in it.');
    }

    public function test_a_rejected_group_is_recorded_as_rejected(): void
    {
        $ctx = $this->makeTenant();
        $this->request($ctx, 'not_in_catalog', 'مدیکوب', '09120000001');

        $page = $this->page($ctx);
        $page->markGroup($page->getGroups()['missing'][0]['key'], 'rejected');

        $page->setStatusFilter('rejected');
        $this->assertCount(1, $page->getGroups()['missing']);
    }

    // ── Restock notification: the merchant, never the customers ─────────

    /** @return array the payload NotificationService was asked to send */
    private function syncProductWithStock(array $ctx, int $wooId, string $name, string $stock): array
    {
        $captured = [];
        $this->mock(NotificationService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('send')->andReturnUsing(function ($chatbot, $event, $data) use (&$captured) {
                $captured[] = ['event' => $event, 'data' => $data];
            });
        });

        $this->inSchema($ctx, function () use ($ctx, $wooId, $name, $stock) {
            app(SyncService::class)->syncProducts($ctx['chatbotId'], [[
                'id' => $wooId, 'name' => $name, 'sku' => 'SKU' . $wooId,
                'price' => 1000, 'currency' => 'IRT', 'stock_status' => $stock,
                'description' => '', 'categories' => [], 'tags' => [],
            ]], $ctx['schema']);
        });

        return $captured;
    }

    public function test_the_merchant_is_notified_when_a_waited_on_product_comes_back(): void
    {
        $ctx = $this->makeTenant();
        Http::fake();

        // It was out of stock, and three people asked.
        $this->syncProductWithStock($ctx, 11, 'کیسه آب گرم فضانورد', 'outofstock');
        $this->request($ctx, 'out_of_stock', 'کیسه آب گرم فضانورد', '09120000001', 11);
        $this->request($ctx, 'out_of_stock', 'کیسه آب گرم فضانورد', '09120000002', 11);
        $this->request($ctx, 'out_of_stock', 'کیسه آب گرم فضانورد', '09120000003', 11);

        // Now it is back.
        $captured = $this->syncProductWithStock($ctx, 11, 'کیسه آب گرم فضانورد', 'instock');

        $this->assertCount(1, $captured);
        $this->assertEquals('restock_waitlist', $captured[0]['event']);
        $this->assertEquals(3, $captured[0]['data']['count']);
        $this->assertEqualsCanonicalizing(
            ['09120000001', '09120000002', '09120000003'],
            $captured[0]['data']['contacts'],
            'The merchant must be handed the actual numbers.'
        );
    }

    public function test_no_sms_is_ever_sent_to_the_people_waiting(): void
    {
        $ctx = $this->makeTenant();
        Http::fake();

        $this->syncProductWithStock($ctx, 11, 'ماگ', 'outofstock');
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120000001', 11);
        $this->syncProductWithStock($ctx, 11, 'ماگ', 'instock');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'payamak-panel.com'));
    }

    public function test_a_product_that_was_already_in_stock_notifies_nobody(): void
    {
        $ctx = $this->makeTenant();
        Http::fake();

        $this->syncProductWithStock($ctx, 11, 'ماگ', 'instock');
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120000001', 11);

        $captured = $this->syncProductWithStock($ctx, 11, 'ماگ', 'instock');

        $this->assertEmpty($captured, 'Only the transition into stock counts, not every sync.');
    }

    public function test_a_first_time_product_notifies_nobody(): void
    {
        $ctx = $this->makeTenant();
        Http::fake();
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120000001', 11);

        // Never seen before: previous stock is unknown, not "out of stock".
        $captured = $this->syncProductWithStock($ctx, 11, 'ماگ', 'instock');

        $this->assertEmpty($captured, 'The first sync must not notify for every product at once.');
    }

    public function test_a_restock_with_nobody_waiting_notifies_nobody(): void
    {
        $ctx = $this->makeTenant();
        Http::fake();

        $this->syncProductWithStock($ctx, 11, 'ماگ', 'outofstock');
        $captured = $this->syncProductWithStock($ctx, 11, 'ماگ', 'instock');

        $this->assertEmpty($captured);
    }

    public function test_an_already_fulfilled_request_does_not_notify_again(): void
    {
        $ctx = $this->makeTenant();
        Http::fake();

        $this->syncProductWithStock($ctx, 11, 'ماگ', 'outofstock');
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120000001', 11);
        $this->inSchema($ctx, fn () => DB::table('leads')->update(['request_status' => 'fulfilled']));

        $captured = $this->syncProductWithStock($ctx, 11, 'ماگ', 'instock');

        $this->assertEmpty($captured);
    }

    // ── Export and page ─────────────────────────────────────────────────

    public function test_the_csv_carries_one_line_per_waiting_person(): void
    {
        $ctx = $this->makeTenant();
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120000001', 10);
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120000002', 10);
        $this->request($ctx, 'not_in_catalog', 'مدیکوب', '09120000003');

        ob_start();
        $this->page($ctx)->exportCsv()->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('09120000001', $csv);
        $this->assertStringContainsString('09120000002', $csv);
        $this->assertStringContainsString('مدیکوب', $csv);
        // Header + 3 rows.
        $this->assertEquals(4, count(array_filter(explode("\n", trim($csv)))));
    }

    public function test_the_page_renders_both_sections(): void
    {
        $ctx = $this->makeTenant();
        $this->request($ctx, 'out_of_stock', 'کیسه آب گرم فضانورد', '09120000001', 11);
        $this->request($ctx, 'not_in_catalog', 'مدیکوب', '09120000002');

        $response = $this->actingAs($ctx['user'], 'web')->get('/portal/product-requests');

        $response->assertOk();
        $response->assertSee(__('requests.section_waiting'), false);
        $response->assertSee(__('requests.section_missing'), false);
        $response->assertSee('کیسه آب گرم فضانورد', false);
        $response->assertSee('مدیکوب', false);
        // The promise that nothing is auto-sent is on the page itself.
        $response->assertSee(__('requests.no_auto_sms_note'), false);
    }

    // ── Feeding the suggestions engine ──────────────────────────────────

    public function test_the_waitlist_becomes_a_suggestion(): void
    {
        $ctx = $this->makeTenant();
        foreach (range(1, 3) as $i) {
            $this->request($ctx, 'not_in_catalog', 'مدیکوب', '0912000200' . $i);
        }

        $found = $this->inSchema($ctx, fn () => app(SuggestionEngine::class)->build($ctx['chatbotId']));
        $suggestion = collect($found)->firstWhere('type', 'missing_from_catalog');

        $this->assertNotNull($suggestion, 'People leaving a number for something you do not stock is a suggestion.');
        $this->assertEquals(3, $suggestion['count']);
        $this->assertEquals('مدیکوب', $suggestion['params']['request']);
        $this->assertNotEmpty($suggestion['conversations']);
    }

    public function test_restock_requests_become_their_own_suggestion(): void
    {
        $ctx = $this->makeTenant();
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120002001', 10);
        $this->request($ctx, 'out_of_stock', 'ماگ', '09120002002', 10);

        $found = $this->inSchema($ctx, fn () => app(SuggestionEngine::class)->build($ctx['chatbotId']));
        $suggestion = collect($found)->firstWhere('type', 'restock_requested');

        $this->assertNotNull($suggestion);
        $this->assertEquals(2, $suggestion['count']);
    }
}
