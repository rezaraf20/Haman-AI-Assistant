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
        $leads = $query->limit(200)->get();
        DB::statement('SET search_path TO public');
        return $leads->all();
    }

    public function setStatusFilter(string $status): void {
        $this->statusFilter = $status;
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
        // No dedicated single-conversation view page exists in the
        // customer portal today — DemandGap is the closest existing
        // destination for "go look at what visitors asked".
        return DemandGap::getUrl();
    }

    public function exportCsv() {
        $tenant = auth()->user()->tenant;
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $leads = DB::table('leads')->orderByDesc('created_at')->get();
        DB::statement('SET search_path TO public');

        $csv = "id,contact,contact_type,question,status,created_at\n";
        foreach ($leads as $lead) {
            $csv .= implode(',', array_map(
                fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"',
                [$lead->id, $lead->contact, $lead->contact_type, $lead->question, $lead->status, $lead->created_at]
            )) . "\n";
        }

        return response()->streamDownload(fn () => print($csv), 'leads-' . now()->format('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }
}
