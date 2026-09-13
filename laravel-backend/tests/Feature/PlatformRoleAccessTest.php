<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{Tenant, Plan, User};
use App\Filament\Pages\TenantConversations;
use App\Services\TenantService;
use App\Support\{PlatformAccess, PlatformAudit};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Resources\TenantResource\Pages\ListTenants;
use Livewire\Livewire;

/**
 * Platform staff roles.
 *
 * The rule that must never break: platform role is not tenant role. This
 * system already shipped a vulnerability where role==='owner' — which every
 * customer's first user has — granted the cross-tenant admin panel. So the
 * tests below check tenant users of every role are refused, and that the
 * new support role is refused everything in the deny list by DIRECT URL,
 * not merely by a hidden menu item.
 */
class PlatformRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = [], ?string $platformRole = null, bool $active = true): User
    {
        $user = User::create(array_merge([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'User', 'email_verified_at' => now(),
        ], $attrs));

        if ($platformRole !== null) {
            // Never mass-assignable — set the way the admin screen does.
            $user->platform_role = $platformRole;
            $user->platform_is_active = $active;
            $user->save();
        }
        return $user;
    }

    private function tenantWithUser(): array
    {
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p-' . Str::random(6), 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 1000, 'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 't-' . Str::random(6), 'name' => 'Customer',
            'email' => Str::random(8) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDay(),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        return [$tenant, $schema];
    }

    // ── The old vulnerability, still closed ─────────────────────────────

    public static function tenantRoles(): array
    {
        // The last three are the ones worth worrying about: a tenant-side
        // role string that READS like platform privilege. users.role is
        // NOT NULL, so there is no null case to cover.
        return [
            ['owner'], ['admin'], ['member'], ['support'],
            ['platform_admin'], ['superadmin'], ['staff'],
        ];
    }

    /**
     * @dataProvider tenantRoles
     * Note 'support' here is a TENANT role string — it must not be confused
     * with platform_role='support'. That confusion is the whole bug class.
     */
    public function test_a_tenant_user_of_any_role_cannot_reach_the_admin_panel(string $role): void
    {
        [$tenant] = $this->tenantWithUser();
        $user = $this->user(['role' => $role, 'tenant_id' => $tenant->id]);

        $this->actingAs($user, 'web')->get('/admin')->assertForbidden();
    }

    public function test_a_tenant_role_string_of_support_grants_nothing(): void
    {
        [$tenant] = $this->tenantWithUser();
        $user = $this->user(['role' => 'support', 'tenant_id' => $tenant->id]);

        $this->assertFalse(PlatformAccess::isStaff($user));
        $this->assertFalse(PlatformAccess::isSupport($user));
        $this->assertNull($user->platform_role);
    }

    // ── Who gets in ─────────────────────────────────────────────────────

    public function test_support_can_reach_the_admin_panel(): void
    {
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web')->get('/admin')->assertOk();
    }

    public function test_admin_can_reach_the_admin_panel(): void
    {
        $admin = $this->user([], 'admin');
        $this->actingAs($admin, 'web')->get('/admin')->assertOk();
    }

    public function test_the_legacy_flag_is_kept_in_step_with_the_new_column(): void
    {
        $admin = $this->user([], 'admin');
        $this->assertTrue((bool) $admin->fresh()->is_platform_admin, 'admin role must satisfy old readers');

        $support = $this->user([], 'support');
        $this->assertFalse((bool) $support->fresh()->is_platform_admin, 'support is not a platform admin');
    }

    // ── Deny list, enforced on the direct URL ───────────────────────────

    public static function adminOnlyPaths(): array
    {
        return [
            'platform margin'   => ['/admin/profit-margin'],
            'platform settings' => ['/admin/settings'],
            'api key values'    => ['/admin/api-keys'],
            'wallet ledger'     => ['/admin/wallet-transactions'],
            'llm credentials'   => ['/admin/llm-provider-profiles'],
            'token pricing'     => ['/admin/token-packages'],
            'chatbot pricing'   => ['/admin/chatbot-type-prices'],
            'staff list'        => ['/admin/platform-users'],
        ];
    }

    /** @dataProvider adminOnlyPaths */
    public function test_support_is_refused_admin_only_pages_by_direct_url(string $path): void
    {
        $support = $this->user([], 'support');

        $response = $this->actingAs($support, 'web')->get($path);

        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            "{$path} must not be reachable by support — hiding the menu item is not authorisation."
        );
    }

    /** @dataProvider adminOnlyPaths */
    public function test_admin_can_reach_the_admin_only_pages(string $path): void
    {
        $admin = $this->user([], 'admin');

        $this->actingAs($admin, 'web')->get($path)->assertOk();
    }

    public function test_the_capability_matrix_matches_the_written_rules(): void
    {
        $support = $this->user([], 'support');
        $admin = $this->user([], 'admin');

        foreach (PlatformAccess::ADMIN_ONLY as $capability) {
            $this->assertFalse(PlatformAccess::can($support, $capability), "support must not have {$capability}");
            $this->assertTrue(PlatformAccess::can($admin, $capability), "admin must have {$capability}");
        }
        foreach (PlatformAccess::STAFF as $capability) {
            $this->assertTrue(PlatformAccess::can($support, $capability), "support must have {$capability}");
        }
    }

    public function test_an_unknown_capability_fails_closed(): void
    {
        $support = $this->user([], 'support');

        $this->assertFalse(
            PlatformAccess::can($support, 'some_feature_added_later'),
            'A capability nobody declared must not default to visible.'
        );
    }

    // ── What support IS allowed ─────────────────────────────────────────

    public function test_support_can_reach_the_pages_it_needs(): void
    {
        $support = $this->user([], 'support');

        foreach (['/admin/tenants', '/admin/chatbots', '/admin/tickets'] as $path) {
            $this->actingAs($support, 'web')->get($path)->assertOk();
        }
    }

    // ── Deactivation ────────────────────────────────────────────────────

    public function test_deactivating_a_support_account_locks_it_out_immediately(): void
    {
        $support = $this->user([], 'support');

        $this->actingAs($support, 'web')->get('/admin')->assertOk();

        // The same thing the admin screen's toggle does.
        $support->platform_is_active = false;
        $support->save();

        // Same authenticated session, next request.
        $this->actingAs($support->fresh(), 'web')->get('/admin')->assertForbidden();
    }

    public function test_a_deactivated_admin_is_locked_out_too(): void
    {
        $admin = $this->user([], 'admin');
        $admin->platform_is_active = false;
        $admin->save();

        $this->actingAs($admin->fresh(), 'web')->get('/admin')->assertForbidden();
        $this->assertFalse(PlatformAccess::isStaff($admin->fresh()));
    }

    // ── Destructive row actions ─────────────────────────────────────────
    //
    // These are hand-written Action::make() calls, NOT Filament's own
    // DeleteAction, so the resource's canDelete() never covered them. Both
    // closures are destructive — one drops a tenant's entire schema — and
    // both were reachable by support until these tests existed.

    public function test_the_tenant_delete_button_does_not_render_for_support(): void
    {
        [$tenant] = $this->tenantWithUser();

        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');
        Livewire::test(ListTenants::class)->assertTableActionHidden('delete', $tenant);

        $admin = $this->user([], 'admin');
        $this->actingAs($admin, 'web');
        Livewire::test(ListTenants::class)->assertTableActionVisible('delete', $tenant);
    }

    public function test_the_capability_behind_the_destructive_actions_is_admin_only(): void
    {
        $support = $this->user([], 'support');
        $admin   = $this->user([], 'admin');

        $this->assertFalse(PlatformAccess::can($support, 'tenant_lifecycle'));
        $this->assertTrue(PlatformAccess::can($admin, 'tenant_lifecycle'));
    }

    public function test_authorize_aborts_for_support_and_passes_for_admin(): void
    {
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');

        try {
            PlatformAccess::authorize('tenant_lifecycle');
            $this->fail('authorize() must abort for support — it is the guard inside the action closure.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        $admin = $this->user([], 'admin');
        $this->actingAs($admin, 'web');
        PlatformAccess::authorize('tenant_lifecycle');
        $this->assertTrue(true, 'admin passes through');
    }

    // ── Support's own affordances ───────────────────────────────────────

    public function test_support_may_open_a_chatbot_to_edit_widget_settings(): void
    {
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');

        $this->assertTrue(\App\Filament\Resources\ChatbotResource::canEdit(new \stdClass()));
        $this->assertFalse(
            \App\Filament\Resources\ChatbotResource::canCreate(),
            'editing settings is support work; creating a chatbot is not.'
        );

        $admin = $this->user([], 'admin');
        $this->actingAs($admin, 'web');
        $this->assertTrue(\App\Filament\Resources\ChatbotResource::canCreate());
    }

    public function test_support_never_sees_the_platform_finance_stats(): void
    {
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');
        $this->assertFalse(\App\Filament\Widgets\RevenueVsCostChart::canView());

        $admin = $this->user([], 'admin');
        $this->actingAs($admin, 'web');
        $this->assertTrue(\App\Filament\Widgets\RevenueVsCostChart::canView());
    }

    public function test_the_ticket_reply_page_states_its_own_gate(): void
    {
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');
        $this->assertTrue(
            \App\Filament\Resources\TicketResource\Pages\ManageTicket::canAccess(),
            'replying to tickets is the core of the support role'
        );

        $tenantUser = $this->user(['role' => 'owner']);
        $this->actingAs($tenantUser, 'web');
        $this->assertFalse(\App\Filament\Resources\TicketResource\Pages\ManageTicket::canAccess());
    }

    // ── Conversation access: reason, logging, masking ───────────────────

    public function test_contact_details_are_masked_before_they_leave_the_server(): void
    {
        $masked = TenantConversations::maskContacts(
            'تماس بگیرید 09121234567 یا ایمیل ali@example.com'
        );

        $this->assertStringNotContainsString('09121234567', $masked);
        $this->assertStringNotContainsString('ali@example.com', $masked);
        $this->assertStringContainsString('0912***4567', $masked);
        $this->assertStringContainsString('a***@example.com', $masked);
    }

    public function test_opening_a_conversation_records_the_reason(): void
    {
        [$tenant] = $this->tenantWithUser();
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');

        $page = new TenantConversations();
        $page->tenantId = (string) $tenant->id;
        $page->setReason('ticket_review');
        $page->openConversation('11111111-1111-4111-8111-111111111111');

        $row = DB::table('platform_audit_log')->where('action', 'conversation_viewed')->first();

        $this->assertNotNull($row);
        $this->assertEquals('ticket_review', $row->reason);
        $this->assertEquals($tenant->id, $row->tenant_id);
        $this->assertEquals($support->id, $row->user_id);
        $this->assertEquals('11111111-1111-4111-8111-111111111111', $row->subject_id);
    }

    public function test_nothing_is_read_before_a_reason_is_given(): void
    {
        [$tenant] = $this->tenantWithUser();
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');

        $page = new TenantConversations();
        $page->tenantId = (string) $tenant->id;

        $this->assertSame([], $page->getConversations());
        $this->assertSame([], $page->getTranscript());
        $this->assertEquals(0, DB::table('platform_audit_log')->count());
    }

    public function test_an_invented_reason_is_refused(): void
    {
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');

        $page = new TenantConversations();
        $page->setReason('because_i_felt_like_it');

        $this->assertNull($page->reason);
        $this->assertEquals(0, DB::table('platform_audit_log')->count());
    }

    public function test_revealing_contacts_is_recorded_as_its_own_event(): void
    {
        [$tenant] = $this->tenantWithUser();
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');

        $page = new TenantConversations();
        $page->tenantId = (string) $tenant->id;
        $page->setReason('customer_request');
        $page->reveal('22222222-2222-4222-8222-222222222222');

        $this->assertTrue($page->isRevealed('22222222-2222-4222-8222-222222222222'));
        $this->assertEquals(1, DB::table('platform_audit_log')->where('action', 'contact_revealed')->count());
    }

    // ── Settings changes are recorded with before and after ─────────────

    public function test_a_settings_change_records_both_values(): void
    {
        $support = $this->user([], 'support');
        $this->actingAs($support, 'web');

        PlatformAudit::record(
            'widget_settings_changed',
            subjectType: 'chatbot',
            subjectId: 'abc',
            changes: PlatformAudit::diff(['name' => 'Old'], ['name' => 'New']),
        );

        $row = DB::table('platform_audit_log')->where('action', 'widget_settings_changed')->first();
        $changes = json_decode($row->changes, true);

        $this->assertEquals('Old', $changes['name']['before']);
        $this->assertEquals('New', $changes['name']['after']);
        $this->assertEquals('support', $row->platform_role);
    }

    public function test_the_diff_only_carries_fields_that_actually_changed(): void
    {
        $changes = PlatformAudit::diff(
            ['a' => 1, 'b' => 'same'],
            ['a' => 2, 'b' => 'same'],
        );

        $this->assertArrayHasKey('a', $changes);
        $this->assertArrayNotHasKey('b', $changes, 'An unchanged field would bury the one that matters.');
    }
}
