<?php
namespace App\Support;

use App\Models\ChatbotIndexEntry;
use App\Models\Tenant;
use Illuminate\Support\Facades\{DB, Cache};

// Five customer-dashboard widgets (ChatbotStatusWidget, CustomerStatsOverview,
// DailyConversationsChart, RecentUnansweredWidget, TopTopicsWidget) each used
// to independently switch into the tenant's schema, run their own query, and
// switch back — five schema switches plus five resets on every cold-cache
// page load, on top of the actual queries, blowing well past the dashboard's
// query budget. One shared cache entry, computed under a single schema
// switch, now serves all five.
class CustomerDashboardData {
    private static function empty(?int $maxTokensMonthly): array {
        return [
            'chatbotStatuses' => [], 'dailyRows' => collect(), 'monthQuestions' => 0,
            'monthUnanswered' => 0, 'recentUnanswered' => [], 'topTopics' => [],
            'newLeadsThisWeek' => 0, 'maxTokensMonthly' => $maxTokensMonthly,
        ];
    }

    public static function forTenant(Tenant $tenant): array {
        return Cache::remember("dashboard:customer:data:{$tenant->id}", 300, function () use ($tenant) {
            $chatbots = ChatbotIndexEntry::where('tenant_id', $tenant->id)->get();

            try {
                return self::computeForTenant($tenant, $chatbots);
            } catch (\Throwable $e) {
                // An incomplete/broken tenant schema must degrade this one
                // tenant's own dashboard, not throw a 500 — see
                // FailedSyncsTable's identical fix for the real incident
                // this traces back to (one tenant with a schema missing
                // sync_jobs entirely took down the *admin* dashboard for
                // every admin before that fix).
                \Illuminate\Support\Facades\Log::warning("CustomerDashboardData: tenant {$tenant->id} ({$tenant->schema_name}) — {$e->getMessage()}");
                // Falls back to a direct lazy-load here (1 query) rather
                // than the combined query below, since that combined query
                // is exactly the tenant-schema statement that just failed.
                return self::empty($tenant->plan?->max_tokens_monthly);
            } finally {
                // Must run even on failure — a query exception would
                // otherwise leave search_path pointed at this tenant's
                // schema for the rest of the request.
                DB::statement('SET search_path TO public');
            }
        });
    }

    private static function computeForTenant(Tenant $tenant, $chatbots): array {
        DB::statement("SET search_path TO {$tenant->schema_name}, public");

        // Two otherwise-unrelated scalar values combined into one round
        // trip instead of two separate queries: max_tokens_monthly lives on
        // the public-schema plans table (still reachable here — search_path
        // includes public alongside the tenant schema), new_leads_count on
        // this tenant's own leads table. Neither depends on the other; this
        // is purely to stay under the dashboard's query budget.
        $scalars = DB::selectOne('
            SELECT
                (SELECT max_tokens_monthly FROM public.plans WHERE id = ?) AS max_tokens_monthly,
                (SELECT COUNT(*) FROM leads WHERE created_at >= ?) AS new_leads_count
        ', [$tenant->plan_id, now()->subDays(7)]);
        $maxTokensMonthly = $scalars->max_tokens_monthly !== null ? (int) $scalars->max_tokens_monthly : null;
        $newLeadsThisWeek = (int) $scalars->new_leads_count;

        $chatbotStatuses = [];
        foreach ($chatbots as $chatbot) {
            $latestSync = DB::table('sync_jobs')
                ->where('chatbot_id', $chatbot->chatbot_id)
                ->orderByDesc('created_at')
                ->first(['status', 'created_at']);

            $chatbotStatuses[] = [
                'name'        => $chatbot->name ?: '—',
                'is_active'   => $chatbot->is_active,
                'sync_status' => $latestSync?->status,
                'last_sync'   => $latestSync?->created_at,
            ];
        }

        $monthStart = now()->startOfMonth()->toDateString();
        $chartStart = now()->subDays(29)->toDateString();
        // Fetched from whichever of the two is earlier so the
        // month-to-date sums below are never missing early-month days
        // that fall outside the 30-day chart window (e.g. on the 31st
        // of a 31-day month, day 1 is 30 days ago — a day the chart's
        // own 30-day window alone would clip).
        $queryStart = min($monthStart, $chartStart);

        $dailyRows = DB::table('analytics_daily')
            ->where('date', '>=', $queryStart)
            ->selectRaw('date, SUM(total_conversations) as convs, SUM(user_messages) as questions, SUM(unanswered_count) as unanswered')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($r) => $r->date instanceof \DateTimeInterface ? $r->date->format('Y-m-d') : substr($r->date, 0, 10));

        $monthQuestions = 0;
        $monthUnanswered = 0;
        foreach ($dailyRows as $date => $row) {
            if ($date >= $monthStart) {
                $monthQuestions += (int) $row->questions;
                $monthUnanswered += (int) $row->unanswered;
            }
        }

        // Same pairing logic as DemandGap.php: the user question
        // immediately preceding each unanswered assistant reply in the
        // same conversation.
        $recentUnanswered = DB::select("
            SELECT u.content AS question, a.created_at
            FROM messages a
            JOIN messages u ON u.conversation_id = a.conversation_id
                AND u.role = 'user'
                AND u.created_at = (
                    SELECT MAX(created_at) FROM messages u2
                    WHERE u2.conversation_id = a.conversation_id
                      AND u2.role = 'user'
                      AND u2.created_at < a.created_at
                )
            WHERE a.role = 'assistant' AND a.is_unanswered = true
            ORDER BY a.created_at DESC
            LIMIT 5
        ");

        // The one query here that genuinely can't be served from
        // analytics_daily — a daily rollup has no per-question text to
        // group by.
        $topTopics = DB::table('messages')
            ->select('content', DB::raw('COUNT(*) as cnt'))
            ->where('role', 'user')
            ->where('created_at', '>=', $monthStart)
            ->groupBy('content')
            ->orderByDesc('cnt')
            ->limit(5)
            ->get()
            ->toArray();

        return compact(
            'chatbotStatuses',
            'dailyRows',
            'monthQuestions',
            'monthUnanswered',
            'recentUnanswered',
            'topTopics',
            'newLeadsThisWeek',
            'maxTokensMonthly',
        );
    }
}
