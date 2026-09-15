<?php
namespace Tests\Feature;

use App\Filament\Pages\{ActivityLog, TenantConversations};
use App\Filament\Resources\PlatformUserResource;
use App\Filament\Resources\TicketResource\Pages\ManageTicket;
use App\Models\{Plan, Tenant, Ticket, User};
use App\Services\TenantService;
use App\Support\PlatformActivity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The activity log.
 *
 * Three things are being defended here, in order of how badly they fail if
 * they break: that staff actions are actually recorded, that support cannot
 * read the record of itself, and that nothing anywhere can delete a row.
 * The last one is checked by reading the source, not just by clicking the
 * page — a delete path added later in a different file would still be a
 * delete path.
 */
class PlatformActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'support'): User
    {
        $user = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Staff', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $user->platform_role = $role;
        $user->platform_is_active = true;
        $user->save();

        return $user;
    }

    private function tenant(): Tenant
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

        return $tenant;
    }

    private function actions(): array
    {
        return DB::table('platform_activity_log')->orderBy('created_at')->pluck('action')->all();
    }

    // ── The trail ───────────────────────────────────────────────────────

    public function test_a_whole_support_session_leaves_a_trail(): void
    {
        $tenant = $this->tenant();
        $support = $this->staff();

        // Auth::login fires the same event Filament's login page does.
        Auth::guard('web')->login($support);

        $page = new TenantConversations();
        $page->tenantId = (string) $tenant->id;
        $page->setReason('ticket_review');
        $page->openConversation('11111111-1111-4111-8111-111111111111');
        $page->reveal('11111111-1111-4111-8111-111111111111');

        Auth::guard('web')->logout();

        $this->assertEquals([
            'login',
            'conversation_list_opened',
            'conversation_viewed',
            'contact_revealed',
            'logout',
        ], $this->actions());

        // Every row carries who, and the conversation rows carry why.
        foreach (DB::table('platform_activity_log')->get() as $row) {
            $this->assertEquals($support->id, $row->user_id);
            $this->assertEquals('support', $row->platform_role);
            $this->assertEquals($support->email, $row->user_email);
        }

        $viewed = DB::table('platform_activity_log')->where('action', 'conversation_viewed')->first();
        $this->assertEquals('ticket_review', $viewed->reason);
        $this->assertEquals($tenant->id, $viewed->tenant_id);
        $this->assertEquals('conversation', $viewed->subject_type);
    }

    public function test_a_failed_sign_in_on_a_staff_account_is_recorded(): void
    {
        $support = $this->staff();

        event(new Failed('web', $support, ['email' => $support->email, 'password' => 'wrong']));

        $this->assertEquals(['login_failed'], $this->actions());
    }

    public function test_a_failed_sign_in_for_someone_who_is_not_staff_is_ignored(): void
    {
        $tenant = $this->tenant();
        $customer = User::create([
            'email' => 'customer@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Customer', 'role' => 'owner', 'tenant_id' => $tenant->id, 'email_verified_at' => now(),
        ]);

        event(new Failed('web', $customer, ['email' => $customer->email, 'password' => 'wrong']));
        // And an address nobody owns, which is what a spray looks like.
        event(new Failed('web', null, ['email' => 'nobody@example.test', 'password' => 'wrong']));

        $this->assertEquals(
            0,
            DB::table('platform_activity_log')->count(),
            'Anyone could otherwise fill this table by POSTing invented addresses.'
        );
    }

    public function test_a_customers_own_sign_in_is_not_recorded(): void
    {
        $tenant = $this->tenant();
        $customer = User::create([
            'email' => 'c@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Customer', 'role' => 'owner', 'tenant_id' => $tenant->id, 'email_verified_at' => now(),
        ]);

        Auth::guard('web')->login($customer);

        $this->assertEquals(0, DB::table('platform_activity_log')->count());
    }

    public function test_a_ticket_reply_and_its_status_change_are_both_recorded(): void
    {
        $tenant = $this->tenant();
        $support = $this->staff();
        $this->actingAs($support, 'web');

        $ticket = Ticket::create([
            'tenant_id' => $tenant->id, 'subject' => 'Help', 'status' => 'open', 'priority' => 'normal',
        ]);

        Livewire::test(ManageTicket::class, ['record' => $ticket->id])
            ->fillForm(['status' => 'answered', 'reply' => 'پاسخ داده شد'])
            ->call('submitReply');

        $this->assertEquals(['ticket_replied', 'ticket_status_changed'], $this->actions());

        $status = DB::table('platform_activity_log')->where('action', 'ticket_status_changed')->first();
        $this->assertEquals('open', json_decode($status->before, true)['status']);
        $this->assertEquals('answered', json_decode($status->after, true)['status']);
        $this->assertEquals((string) $ticket->id, $status->subject_id);

        // The customer's words are not copied into a table that outlives
        // the ticket; only the fact of the reply is.
        $reply = DB::table('platform_activity_log')->where('action', 'ticket_replied')->first();
        $this->assertStringNotContainsString('پاسخ داده شد', (string) $reply->after);
    }

    public function test_a_settings_change_records_only_what_changed(): void
    {
        $support = $this->staff();
        $this->actingAs($support, 'web');

        [$before, $after] = PlatformActivity::diff(
            ['welcome' => 'Hi', 'colour' => 'blue'],
            ['welcome' => 'Hello', 'colour' => 'blue'],
        );
        PlatformActivity::record('chatbot_settings_changed', before: $before, after: $after);

        $row = DB::table('platform_activity_log')->first();

        $this->assertEquals(['welcome' => 'Hi'], json_decode($row->before, true));
        $this->assertEquals(['welcome' => 'Hello'], json_decode($row->after, true));
    }

    public function test_a_form_restating_a_number_as_a_string_is_not_a_change(): void
    {
        [$before, $after] = PlatformActivity::diff(['price' => 12], ['price' => '12']);

        $this->assertSame([], $after, 'Re-saving an unchanged form must not fill the log.');
        $this->assertSame([], $before);
    }

    public function test_null_becoming_false_is_still_recorded(): void
    {
        [, $after] = PlatformActivity::diff(['flag' => null], ['flag' => false]);

        $this->assertArrayHasKey(
            'flag',
            $after,
            'Never set and switched off are different facts about an account.'
        );
    }

    public function test_the_request_ip_and_user_agent_are_captured(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin, 'web');

        // Bind the request the recorder will actually read, rather than
        // server variables for a request this test never makes.
        app()->instance('request', \Illuminate\Http\Request::create(
            '/admin/tenants', 'GET', [], [], [],
            ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'ProbeBrowser/1.0'],
        ));

        PlatformActivity::record('cache_cleared');

        $row = DB::table('platform_activity_log')->first();
        $this->assertEquals('203.0.113.9', $row->ip);
        $this->assertEquals('ProbeBrowser/1.0', $row->user_agent);
    }

    public function test_a_write_failure_never_breaks_the_action_it_was_recording(): void
    {
        $support = $this->staff();
        $this->actingAs($support, 'web');

        // Inside a savepoint: Postgres aborts the whole transaction on any
        // error, so without one the failed insert would poison the rest of
        // the test. Rolling back to the savepoint both undoes the rename
        // and clears the aborted state.
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE platform_activity_log RENAME TO platform_activity_log_moved');

            PlatformActivity::record('login');   // must not throw

            $this->assertTrue(true, 'A broken log must not stop support working.');
        } finally {
            DB::rollBack();
        }

        // And the connection is still usable afterwards.
        $this->assertEquals(0, DB::table('platform_activity_log')->count());
    }

    // ── Support must not read the log ───────────────────────────────────

    public function test_support_cannot_reach_the_activity_log_by_direct_url(): void
    {
        $support = $this->staff();

        $response = $this->actingAs($support, 'web')->get('/admin/activity-log');

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_admin_can_reach_the_activity_log(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin, 'web')->get('/admin/activity-log')->assertOk();
    }

    public function test_support_cannot_export_the_log(): void
    {
        $support = $this->staff();
        $this->actingAs($support, 'web');

        $this->assertFalse(ActivityLog::canAccess());
        $this->assertFalse(ActivityLog::shouldRegisterNavigation());
    }

    public function test_a_tenant_user_cannot_reach_the_activity_log(): void
    {
        $tenant = $this->tenant();
        $user = User::create([
            'email' => 'owner@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Owner', 'role' => 'owner', 'tenant_id' => $tenant->id, 'email_verified_at' => now(),
        ]);

        $this->actingAs($user, 'web')->get('/admin/activity-log')->assertForbidden();
    }

    // ── Nothing can delete a row ────────────────────────────────────────

    public function test_no_source_file_deletes_from_the_activity_log(): void
    {
        // Matches a delete aimed at THIS table, rather than any file that
        // merely mentions it — PlatformUserResource both reads the log and
        // revokes API tokens with ->delete(), and only one of those is a
        // problem.
        $patterns = [
            'query-builder delete' => '/DB::table\(\s*[\'"]platform_activity_log[\'"]\s*\)(?:\s*->(?!delete|truncate)\w+\([^;]*?\))*\s*->\s*(?:delete|truncate)\s*\(/s',
            'raw delete'           => '/(?:DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+["\']?platform_activity_log/i',
            'schema drop'          => '/Schema::drop(?:IfExists)?\(\s*[\'"]platform_activity_log/',
        ];

        $offenders = [];
        foreach ($this->phpSources() as $file) {
            $source = file_get_contents($file);
            if (!str_contains($source, 'platform_activity_log')) continue;

            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $source)) {
                    $offenders[] = basename($file) . ': ' . $label;
                }
            }
        }

        $this->assertSame([], $offenders, 'The activity log must be append-only.');
    }

    public function test_the_pattern_that_guards_the_log_would_catch_a_real_delete(): void
    {
        // Guarding the guard: a scan that silently matches nothing would
        // pass for ever while the table was being emptied.
        $pattern = '/DB::table\(\s*[\'"]platform_activity_log[\'"]\s*\)(?:\s*->(?!delete|truncate)\w+\([^;]*?\))*\s*->\s*(?:delete|truncate)\s*\(/s';

        $this->assertSame(1, preg_match($pattern, "DB::table('platform_activity_log')->delete();"));
        $this->assertSame(1, preg_match($pattern, "DB::table('platform_activity_log')->where('id', \$id)->delete();"));
        $this->assertSame(0, preg_match($pattern, "DB::table('platform_activity_log')->update(['before' => null]);"));
        $this->assertSame(0, preg_match($pattern, "\$record->tokens()->delete();"));
    }

    public function test_no_eloquent_model_is_bound_to_the_activity_log(): void
    {
        // A model would hand every caller a ->delete() and a ->truncate()
        // that the scan above could not see.
        foreach ($this->phpSources() as $file) {
            if (!str_contains($file, DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR)) continue;

            $this->assertStringNotContainsString(
                'platform_activity_log',
                file_get_contents($file),
                basename($file) . ' binds a model to the append-only log.'
            );
        }
    }

    public function test_the_activity_log_page_offers_no_destructive_action(): void
    {
        $page  = file_get_contents(app_path('Filament/Pages/ActivityLog.php'));
        $blade = file_get_contents(resource_path('views/filament/pages/activity-log.blade.php'));

        foreach (['DeleteAction', 'BulkAction', 'deleteAction', 'wire:click="delete'] as $needle) {
            $this->assertStringNotContainsString($needle, $page);
            $this->assertStringNotContainsString($needle, $blade);
        }

        // Not even an accidental one: the page's only DB verb is select.
        $this->assertStringNotContainsString('->update(', $page);
        $this->assertStringNotContainsString('->insert(', $page);
    }

    public function test_the_page_has_no_route_that_could_mutate_the_log(): void
    {
        $mutating = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'activity-log'))
            ->filter(fn ($route) => (bool) array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))
            ->map(fn ($route) => implode('|', $route->methods()) . ' ' . $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $mutating);
    }

    // ── Retention ───────────────────────────────────────────────────────

    public function test_retention_blanks_payloads_and_keeps_every_row(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin, 'web');

        PlatformActivity::record('chatbot_settings_changed', before: ['a' => 1], after: ['a' => 2]);
        PlatformActivity::record('login');
        DB::table('platform_activity_log')->update(['created_at' => now()->subMonths(19)]);

        // One recent row, which must be left completely alone.
        PlatformActivity::record('contact_revealed', before: ['x' => 'old'], after: ['x' => 'new']);

        $this->artisan('haman:prune-activity-log')->assertExitCode(0);

        $this->assertEquals(3, DB::table('platform_activity_log')->count(), 'No row may be deleted.');

        $old = DB::table('platform_activity_log')->where('action', 'chatbot_settings_changed')->first();
        $this->assertNull($old->before);
        $this->assertNull($old->after);
        $this->assertEquals('chatbot_settings_changed', $old->action, 'The row itself stays readable.');
        $this->assertNotNull($old->user_id);
        $this->assertNotNull($old->created_at);

        $recent = DB::table('platform_activity_log')->where('action', 'contact_revealed')->first();
        $this->assertNotNull($recent->before, 'A row inside the window must be untouched.');
        $this->assertNotNull($recent->after);
    }

    public function test_retention_dry_run_changes_nothing(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin, 'web');

        PlatformActivity::record('chatbot_settings_changed', before: ['a' => 1], after: ['a' => 2]);
        DB::table('platform_activity_log')->update(['created_at' => now()->subMonths(24)]);

        $this->artisan('haman:prune-activity-log --dry-run')->assertExitCode(0);

        $this->assertNotNull(DB::table('platform_activity_log')->first()->before);
    }

    // ── Filters, export, summary ────────────────────────────────────────

    public function test_filters_narrow_the_rows(): void
    {
        $tenant = $this->tenant();
        $admin = $this->staff('admin');
        $this->actingAs($admin, 'web');

        PlatformActivity::record('login');
        PlatformActivity::record('cache_cleared', tenantId: (string) $tenant->id);

        $page = new ActivityLog();
        $page->mount();
        $this->assertCount(2, $page->getRows());

        $page->actionFilter = 'cache_cleared';
        $this->assertCount(1, $page->getRows());
        $this->assertEquals(1, $page->getTotal());

        $page->actionFilter = null;
        $page->tenantFilter = (string) $tenant->id;
        $this->assertCount(1, $page->getRows());

        $page->tenantFilter = null;
        $page->fromFilter = now()->addDay()->toDateString();
        $this->assertCount(0, $page->getRows(), 'A future start date must exclude everything.');
    }

    public function test_the_csv_export_carries_the_filtered_rows(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin, 'web');

        PlatformActivity::record('cache_cleared', before: ['k' => 'old'], after: ['k' => 'new']);

        $page = new ActivityLog();
        $page->mount();

        ob_start();
        $page->exportCsv()->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('cache_cleared', $csv);
        $this->assertStringContainsString($admin->email, $csv);
        $this->assertStringContainsString('user_agent', $csv);
    }

    public function test_the_staff_summary_is_read_from_the_log(): void
    {
        $support = $this->staff();
        $this->actingAs($support, 'web');

        PlatformActivity::record('login');
        PlatformActivity::record('ticket_replied');
        PlatformActivity::record('ticket_replied');
        PlatformActivity::record('conversation_viewed');

        // Older than the window — must not be counted.
        PlatformActivity::record('ticket_replied');
        DB::table('platform_activity_log')
            ->orderByDesc('created_at')->limit(1)
            ->update(['created_at' => now()->subDays(45)]);

        $summary = PlatformUserResource::activitySummary($support->fresh());

        $this->assertNotNull($summary['last_login']);
        $this->assertEquals(2, $summary['tickets']);
        $this->assertEquals(1, $summary['conversations']);
    }

    /** @return string[] */
    private function phpSources(): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->getExtension() === 'php') $files[] = $file->getPathname();
        }
        return $files;
    }
}
