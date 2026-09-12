<?php
namespace App\Filament\Customer\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * "If we leave someone unanswered in live chat, we lose access to them" —
 * the real interview quote this whole feature traces back to. A plain
 * Filament ->query()-driven table would need to run against a tenant-
 * schema Eloquent model with search_path switched mid-request, which this
 * app has no proven pattern for yet (every other tenant-schema listing —
 * MyChatbots — sidesteps it entirely via a public-schema flattened index
 * table, chatbot_index). leads has no such index, so this follows
 * DemandGap.php's simpler, already-proven pattern instead: fetch manually
 * with an explicit SET search_path, render in a plain Blade table.
 */
class Leads extends Page {
    protected static string $view = 'filament.customer.pages.leads';
    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    public string $statusFilter = 'all'; // all | new | contacted | closed
    /** all | unanswered | volunteered | out_of_stock | not_in_catalog —
     *  a shop chasing restock requests wants to see only those, not every
     *  lead the bot ever collected. */
    public string $typeFilter = 'all';

    public static function getNavigationLabel(): string { return __('leads.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('leads.nav'); }

    // Deliberately no getNavigationBadge() here, unlike Tickets/TenantResource
    // — Filament renders the nav on every customer-portal page, not just this
    // one, so a badge here would mean 3 extra queries (schema switch + count
    // + reset) on every single page load platform-wide just to show a
    // number nobody explicitly asked for. The dashboard's own "new leads
    // this week" card (CustomerStatsOverview) already surfaces this without
    // that per-page cost, reusing CustomerDashboardData's single shared
    // schema switch instead of a standalone one.

    public function getLeads(): array {
        $tenant = auth()->user()->tenant;
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $query = DB::table('leads')->orderByDesc('created_at');
        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->typeFilter !== 'all') {
            $query->where('type', $this->typeFilter);
        }
        $leads = $query->limit(200)->get();
        DB::statement('SET search_path TO public');
        return $leads->all();
    }

    public function setStatusFilter(string $status): void {
        $this->statusFilter = $status;
    }

    public function setTypeFilter(string $type): void {
        $this->typeFilter = $type;
    }

    public function typeOptions(): array {
        return [
            'all'            => __('leads.type_all'),
            'out_of_stock'   => __('leads.type_out_of_stock'),
            'not_in_catalog' => __('leads.type_not_in_catalog'),
            'unanswered'     => __('leads.type_unanswered'),
            'volunteered'    => __('leads.type_volunteered'),
        ];
    }

    /** A plain match rather than a lang-key guess: __() hands back the key
     *  itself for an unknown type, so a bad value would render as
     *  "leads.type_x" instead of something a human can read. */
    public function typeLabel(?string $type): string {
        return match ($type) {
            'out_of_stock'   => __('leads.type_out_of_stock'),
            'not_in_catalog' => __('leads.type_not_in_catalog'),
            'volunteered'    => __('leads.type_volunteered'),
            'unanswered'     => __('leads.type_unanswered'),
            default          => (string) $type,
        };
    }

    public function markContacted(string $leadId): void {
        $tenant = auth()->user()->tenant;
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        DB::table('leads')->where('id', $leadId)->update(['status' => 'contacted']);
        DB::statement('SET search_path TO public');
        Notification::make()->title(__('leads.marked_contacted'))->success()->send();
    }

    public function markClosed(string $leadId): void {
        $tenant = auth()->user()->tenant;
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        DB::table('leads')->where('id', $leadId)->update(['status' => 'closed']);
        DB::statement('SET search_path TO public');
        Notification::make()->title(__('leads.marked_closed'))->success()->send();
    }

    public function conversationUrl(string $conversationId): string {
        return Conversations::getUrl() . '?id=' . urlencode($conversationId);
    }

    public function exportCsv() {
        $tenant = auth()->user()->tenant;
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $leads = DB::table('leads')->orderByDesc('created_at')->get();
        DB::statement('SET search_path TO public');

        // requested_item is the column whoever makes the call actually
        // needs — "someone wants a callback" is useless without "about what".
        $csv = "\xEF\xBB\xBFid,contact,contact_type,type,requested_item,question,status,created_at\n";
        foreach ($leads as $lead) {
            $csv .= implode(',', array_map(
                fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"',
                [$lead->id, $lead->contact, $lead->contact_type, $lead->type ?? '',
                 $lead->requested_item ?? '', $lead->question, $lead->status, $lead->created_at]
            )) . "\n";
        }

        return response()->streamDownload(fn () => print($csv), 'leads-' . now()->format('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }
}
