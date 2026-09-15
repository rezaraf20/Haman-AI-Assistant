<?php
namespace Tests\Feature;

use App\Models\{Plan, Tenant, User};
use App\Services\SyncTriggerService;
use App\Support\{PlatformAccess, Settings};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manual sync.
 *
 * Sync had only ever run in one direction — the plugin pushed when it felt
 * like it — so a customer whose catalogue had gone stale could not be helped
 * by anyone, even though `sync_triggered` was already a registered
 * activity-log action. These tests pin the two things that make the feature
 * worth having: that the role gate holds, and that each failure says what is
 * actually wrong instead of "something went wrong".
 */
class ManualSyncTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'shop.example.com';
    private const TRIGGER = 'https://shop.example.com/wp-json/haman/v1/trigger-sync';
    private const LIVE = 'https://shop.example.com/wp-json/haman/v1/live-query';

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
    }

    private function tenant(): array
    {
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p-' . Str::random(6), 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 1000, 'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 't-' . Str::random(6), 'name' => 'Shop',
            'email' => Str::random(8) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDay(),
            'settings' => ['webhook_secret' => 'top-secret'],
        ]);

        // A real tenant schema: sync_jobs lives there, not in public.
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(\App\Services\TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();

        // sync_jobs.chatbot_id is a foreign key into the tenant's own
        // chatbots table, so the row has to exist there too.
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Bot', 'type' => 'ecommerce', 'status' => 'active',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'name' => 'Bot', 'primary_domain' => self::DOMAIN, 'is_active' => true, 'created_at' => now(),
        ]);

        return [$tenant, $chatbotId];
    }

    private function staff(string $role): User
    {
        $user = User::create([
            'email' => Str::random(8) . '@example.test', 'password' => bcrypt('x'),
            'password_hash' => bcrypt('x'), 'name' => 'S', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $user->platform_role = $role;
        $user->platform_is_active = true;
        $user->save();

        return $user;
    }

    // ── the happy path ──────────────────────────────────────────────────

    public function test_a_successful_trigger_reports_what_changed(): void
    {
        [$tenant, $chatbotId] = $this->tenant();

        // The job is written DURING the call, when the site's push is
        // processed — so the fake creates it, the way reality does. A row
        // inserted beforehand is correctly ignored as pre-existing, which
        // is the whole point of the before-id guard.
        $schema = $tenant->schema_name;
        Http::fake([self::TRIGGER => function () use ($schema, $chatbotId) {
            DB::statement("SET search_path TO {$schema}, public");
            DB::table('sync_jobs')->insert([
                'id' => (string) Str::uuid(), 'chatbot_id' => $chatbotId, 'job_type' => 'products',
                'status' => 'completed', 'items_processed' => 5,
                'result' => json_encode(['new' => 2, 'updated' => 1, 'skipped' => 2, 'deleted' => 0, 'failed' => 0]),
                'created_at' => now()->addSecond(),   // sync_jobs has no updated_at
            ]);
            DB::statement('SET search_path TO public');

            return Http::response([
                'ok' => true, 'plugin_version' => '1.9.0', 'duration_seconds' => 7,
                'pushed' => ['products' => ['synced' => 5]],
            ], 200);
        }]);

        $result = app(SyncTriggerService::class)->trigger($tenant, $chatbotId);

        $this->assertTrue($result['ok']);
        $this->assertSame('1.9.0', $result['plugin_version']);
        $this->assertSame(2, $result['counts']['new']);
        $this->assertSame(1, $result['counts']['updated']);
        $this->assertSame(2, $result['counts']['skipped']);
        $this->assertSame(0, $result['counts']['deleted']);
    }

    public function test_the_request_is_signed_with_the_tenant_secret(): void
    {
        [$tenant, $chatbotId] = $this->tenant();
        Http::fake([self::TRIGGER => Http::response(['ok' => true], 200)]);

        app(SyncTriggerService::class)->trigger($tenant, $chatbotId);

        Http::assertSent(function ($request) {
            $expected = 'sha256=' . hash_hmac('sha256', $request->body(), 'top-secret');
            return $request->url() === self::TRIGGER
                && $request->header('X-Haman-Signature')[0] === $expected;
        });
    }

    // ── each failure names itself ───────────────────────────────────────

    public function test_nothing_installed_is_reported_as_a_missing_plugin(): void
    {
        [$tenant, $chatbotId] = $this->tenant();

        // 404 on trigger-sync AND on live-query: no plugin at all.
        Http::fake([
            self::TRIGGER => Http::response('', 404),
            self::LIVE    => Http::response('', 404),
        ]);

        $result = app(SyncTriggerService::class)->trigger($tenant, $chatbotId);

        $this->assertSame('plugin_missing', $result['reason']);
        $this->assertStringContainsString(self::DOMAIN, __($result['message_key'], $result['params']));
    }

    public function test_an_old_plugin_is_reported_as_outdated_not_missing(): void
    {
        [$tenant, $chatbotId] = $this->tenant();

        // 404 on trigger-sync but live-query answers: installed since 1.4,
        // just too old to have the new route. A different conversation.
        Http::fake([
            self::TRIGGER => Http::response('', 404),
            self::LIVE    => Http::response(['error' => 'Invalid signature'], 403),
        ]);

        $result = app(SyncTriggerService::class)->trigger($tenant, $chatbotId);

        $this->assertSame('plugin_outdated', $result['reason']);
        $this->assertSame('1.9.0', $result['params']['required']);
    }

    public function test_every_failure_mode_has_its_own_reason_and_message(): void
    {
        [$tenant, $chatbotId] = $this->tenant();

        $cases = [
            [403, [], 'bad_secret'],
            [409, ['error' => 'not_configured'], 'not_configured'],
            [503, ['error' => 'sync_unavailable'], 'sync_unavailable'],
            [429, ['retry_after_hours' => 1], 'rate_limited'],
            [500, [], 'failed'],
        ];

        $status = 200;
        $body = [];
        Http::fake([
            self::TRIGGER => function () use (&$status, &$body) {
                return Http::response($body, $status);
            },
            self::LIVE => function () {
                return Http::response('', 404);
            },
        ]);

        foreach ($cases as [$nextStatus, $nextBody, $reason]) {
            $status = $nextStatus;
            $body = $nextBody;
            $result = app(SyncTriggerService::class)->trigger($tenant, $chatbotId);

            $this->assertFalse($result['ok'], "HTTP {$status}");
            $this->assertSame($reason, $result['reason'], "HTTP {$status}");

            // A message key that resolves to nothing would surface as the
            // key itself in the panel, which is exactly the generic-error
            // experience this feature exists to avoid.
            $message = __($result['message_key'], $result['params'] ?? []);
            $this->assertNotSame($result['message_key'], $message, $result['message_key']);
            $this->assertNotEmpty($message);
        }
    }

    public function test_an_unreachable_site_is_not_reported_as_a_missing_plugin(): void
    {
        [$tenant, $chatbotId] = $this->tenant();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $result = app(SyncTriggerService::class)->trigger($tenant, $chatbotId);

        $this->assertSame('unreachable', $result['reason']);
    }

    public function test_a_chatbot_with_no_domain_fails_before_any_request(): void
    {
        [$tenant, $chatbotId] = $this->tenant();
        DB::table('chatbot_index')->where('chatbot_id', $chatbotId)->update(['primary_domain' => null]);
        Http::fake();

        $result = app(SyncTriggerService::class)->trigger($tenant, $chatbotId);

        $this->assertSame('no_domain', $result['reason']);
        Http::assertNothingSent();
    }

    // ── the role gate ───────────────────────────────────────────────────

    public function test_support_may_trigger_a_sync_and_a_tenant_user_may_not(): void
    {
        $this->actingAs($this->staff('support'), 'web');
        $this->assertTrue(PlatformAccess::allows('sync_operate'));

        $this->actingAs($this->staff('admin'), 'web');
        $this->assertTrue(PlatformAccess::allows('sync_operate'));

        $customer = User::create([
            'email' => 'c@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'C', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $this->actingAs($customer, 'web');
        $this->assertFalse(PlatformAccess::allows('sync_operate'));
    }

    public function test_the_admin_action_is_gated_and_logged(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/TenantResource.php'));

        $this->assertStringContainsString("Action::make('manualSync')", $source);
        $this->assertStringContainsString("PlatformAccess::authorize('sync_operate')", $source);
        $this->assertStringContainsString("'sync_triggered'", $source);
    }

    public function test_sync_triggered_is_a_declared_activity_action(): void
    {
        $this->assertContains('sync_triggered', \App\Support\PlatformActivity::allActions());
    }

    // ── the customer-side daily cap ─────────────────────────────────────

    public function test_the_portal_cap_is_a_setting_with_a_default(): void
    {
        $this->assertSame(3, Settings::get('limits.manual_sync_per_tenant_per_day'));

        Settings::set('limits.manual_sync_per_tenant_per_day', 1);
        $this->assertSame(1, Settings::get('limits.manual_sync_per_tenant_per_day'));
    }

    public function test_the_portal_counts_per_tenant_not_per_chatbot(): void
    {
        // A tenant with four chatbots must not get four times the budget:
        // the thing being bounded is their embedding spend.
        $source = file_get_contents(app_path('Filament/Customer/Pages/MyChatbots.php'));

        $this->assertStringContainsString('manual-sync:{$tenant->id}:', $source);
        $this->assertStringNotContainsString('manual-sync:{$record->chatbot_id}', $source);
    }

    public function test_a_failed_sync_does_not_burn_the_customers_allowance(): void
    {
        $source = file_get_contents(app_path('Filament/Customer/Pages/MyChatbots.php'));

        // The increment is guarded on the call having succeeded.
        $this->assertMatchesRegularExpression('/if \(\$ok && \$cap > 0\)/', $source);
    }
}
