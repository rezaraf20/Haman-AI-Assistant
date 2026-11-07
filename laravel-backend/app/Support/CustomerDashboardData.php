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
//
// Every number here comes from a real table — analytics_daily (rolled up
// nightly by AggregateAnalyticsJob, so "this month" always means "through
// yesterday"), messages, leads, sync_jobs, documents, api_keys, plans and
// wallet_transactions. Nothing is estimated, sampled or filled in: a metric
// with no rows behind it comes back as zero or null, and the widgets render
// that as an explicit empty state rather than a number.
class CustomerDashboardData {
    /**
     * Versioned for the same reason CustomerOnboarding's key is: this is
     * cached across a deploy, and a release that adds a key here would
     * otherwise read back an entry the old code wrote without it.
     */
    private static function cacheKey(Tenant $tenant): string {
        return "dashboard:customer:data:v2:{$tenant->id}";
    }

    private static function empty(?int $maxTokensMonthly): array {
        return [
            'chatbotStatuses' => [], 'dailyRows' => collect(), 'monthQuestions' => 0,
            'monthUnanswered' => 0, 'recentUnanswered' => [], 'topTopics' => [],
            'newLeadsThisWeek' => 0, 'maxTokensMonthly' => $maxTokensMonthly,
            'intentTotals' => [], 'profileGaps' => [],
            'monthConversations' => 0, 'prevConversations' => null, 'prevUnanswered' => null,
            'comparedDays' => 0, 'plan' => null, 'walletMonthDelta' => 0, 'walletMonthCount' => 0,
            'primaryDomain' => null,
        ];
    }

    public static function forTenant(Tenant $tenant): array {
        return Cache::remember(self::cacheKey($tenant), 300, function () use ($tenant) {
            // plugin_connected rides along here instead of costing its own
            // query — the same definition NotifyUnconnectedChatbotsCommand
            // uses: a chatbot has connected once any key bound to it has
            // authenticated at least once.
            $chatbots = ChatbotIndexEntry::where('tenant_id', $tenant->id)
                ->select('chatbot_index.*')
                ->selectRaw('EXISTS(SELECT 1 FROM api_keys k WHERE k.chatbot_id = chatbot_index.chatbot_id AND k.last_used_at IS NOT NULL) AS plugin_connected')
                ->get();

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
        //
        // The plan's name and the wallet's month-to-date movement ride in
        // the same statement for the same reason.
        $monthStartAt = now()->startOfMonth();
        $scalars = DB::selectOne("
            SELECT
                (SELECT max_tokens_monthly FROM public.plans WHERE id = ?) AS max_tokens_monthly,
                (SELECT COUNT(*) FROM leads WHERE created_at >= ?) AS new_leads_count,
                (SELECT name FROM public.plans WHERE id = ?) AS plan_name,
                (SELECT name_en FROM public.plans WHERE id = ?) AS plan_name_en,
                (SELECT slug FROM public.plans WHERE id = ?) AS plan_slug,
                (SELECT COALESCE(SUM(amount_toman), 0) FROM public.wallet_transactions
                    WHERE tenant_id = ? AND status = 'completed' AND created_at >= ?) AS wallet_month_delta,
                (SELECT COUNT(*) FROM public.wallet_transactions
                    WHERE tenant_id = ? AND status = 'completed' AND created_at >= ?) AS wallet_month_count
        ", [
            $tenant->plan_id, now()->subDays(7),
            $tenant->plan_id, $tenant->plan_id, $tenant->plan_id,
            $tenant->id, $monthStartAt, $tenant->id, $monthStartAt,
        ]);
        $maxTokensMonthly = $scalars->max_tokens_monthly !== null ? (int) $scalars->max_tokens_monthly : null;
        $newLeadsThisWeek = (int) $scalars->new_leads_count;
        $plan = $scalars->plan_name !== null
            ? ['name' => $scalars->plan_name, 'name_en' => $scalars->plan_name_en, 'slug' => $scalars->plan_slug]
            : null;
        $walletMonthDelta = (int) $scalars->wallet_month_delta;
        $walletMonthCount = (int) $scalars->wallet_month_count;

        // One statement for every chatbot's profile, latest sync and
        // document count. This used to be one sync_jobs query per chatbot
        // plus a separate profile query — a cost that grew with the number
        // of chatbots a tenant owns.
        $bots = \App\Models\Tenant\Chatbot::whereIn('id', $chatbots->pluck('chatbot_id'))
            ->select(['id', 'name', 'business_profile'])
            ->selectRaw('(SELECT status FROM sync_jobs s WHERE s.chatbot_id = chatbots.id ORDER BY s.created_at DESC LIMIT 1) AS latest_sync_status')
            ->selectRaw('(SELECT created_at FROM sync_jobs s WHERE s.chatbot_id = chatbots.id ORDER BY s.created_at DESC LIMIT 1) AS latest_sync_at')
            ->selectRaw('(SELECT COUNT(*) FROM documents d WHERE d.chatbot_id = chatbots.id) AS document_count')
            ->get()
            ->keyBy('id');

        $chatbotStatuses = [];
        $primaryDomain = null;
        foreach ($chatbots as $chatbot) {
            $bot = $bots->get($chatbot->chatbot_id);
            $primaryDomain ??= $chatbot->primary_domain ?: null;

            $chatbotStatuses[] = [
                'chatbot_id'       => $chatbot->chatbot_id,
                'name'             => $chatbot->name ?: '—',
                'is_active'        => $chatbot->is_active,
                'plugin_connected' => (bool) $chatbot->plugin_connected,
                'sync_status'      => $bot?->latest_sync_status,
                'last_sync'        => $bot?->latest_sync_at,
                'document_count'   => (int) ($bot?->document_count ?? 0),
            ];
        }

        $monthStart = $monthStartAt->toDateString();
        $chartStart = now()->subDays(29)->toDateString();

        // Month-over-month on equal footing: analytics_daily only ever holds
        // complete days (AggregateAnalyticsJob rolls up yesterday), so "this
        // month" covers day 1 through yesterday, and it is compared with the
        // same number of days at the start of last month — never a partial
        // month against a whole one. On the 1st there is no complete day
        // yet, so there is nothing to compare and the previous-period
        // values come back null.
        $comparedDays = now()->day - 1;
        $prevStart = now()->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $comparedDays > 0
            ? min($prevStart->copy()->addDays($comparedDays - 1)->toDateString(), $prevStart->copy()->endOfMonth()->toDateString())
            : null;

        // Fetched from whichever is earliest — last month's start, this
        // month's start, or the 30-day chart window — so none of the sums
        // below miss days outside the chart's own window (e.g. on the 31st
        // of a 31-day month, day 1 is 30 days ago).
        $queryStart = min($prevStart->toDateString(), $monthStart, $chartStart);

        // Ungrouped/raw rather than a GROUP BY SUM aggregate — this also
        // carries intent_counts (see below), which can't be summed
        // server-side by a plain SUM() the way the numeric columns can.
        // Fetching once and reducing in PHP keeps this at one query total
        // instead of a second round trip just for the jsonb column
        // (DashboardWidgetsTest enforces a hard query-count budget on this
        // whole cached computation).
        $analyticsRaw = DB::table('analytics_daily')
            ->where('date', '>=', $queryStart)
            ->get(['date', 'total_conversations', 'user_messages', 'unanswered_count', 'intent_counts']);

        $dailyRows = collect();
        $intentTotals = [];
        foreach ($analyticsRaw as $row) {
            $dateKey = $row->date instanceof \DateTimeInterface ? $row->date->format('Y-m-d') : substr($row->date, 0, 10);
            if (!$dailyRows->has($dateKey)) {
                $dailyRows->put($dateKey, (object) ['convs' => 0, 'questions' => 0, 'unanswered' => 0]);
            }
            $agg = $dailyRows->get($dateKey);
            $agg->convs += (int) $row->total_conversations;
            $agg->questions += (int) $row->user_messages;
            $agg->unanswered += (int) $row->unanswered_count;

            // doc-04 "Intent analytics" — {intent: count} summed across
            // every chatbot and day (this row set is one row per chatbot per
            // date, so a tenant with several chatbots can have more than one
            // row for the same date).
            // Only the chart's own 30 days — the rows fetched above reach
            // back to the start of last month for the comparison sums, and
            // the intents widget is labelled as a 30-day view.
            $counts = $dateKey >= $chartStart ? (json_decode($row->intent_counts ?? '{}', true) ?: []) : [];
            foreach ($counts as $intent => $cnt) {
                $intentTotals[$intent] = ($intentTotals[$intent] ?? 0) + (int) $cnt;
            }
        }
        arsort($intentTotals);

        // The business-profile onboarding banner, from the same $bots
        // statement above (no query of its own) — see
        // Chatbot::businessProfileMissingCore() for what "basic" means and
        // BusinessProfileWarning for how this is rendered. Reuses that
        // model method directly (rather than re-deriving the same four
        // checks here) so the banner and the WidgetSettings warning can
        // never drift out of sync with each other.
        $profileGaps = [];
        foreach ($bots as $bot) {
            $missing = $bot->businessProfileMissingCore();
            if ($missing) {
                $profileGaps[] = ['chatbot_id' => $bot->id, 'name' => $bot->name ?: '—', 'missing' => $missing];
            }
        }

        $monthQuestions = 0;
        $monthUnanswered = 0;
        $monthConversations = 0;
        $prevConversations = 0;
        $prevUnanswered = 0;
        foreach ($dailyRows as $date => $row) {
            if ($date >= $monthStart) {
                $monthQuestions += (int) $row->questions;
                $monthUnanswered += (int) $row->unanswered;
                $monthConversations += (int) $row->convs;
            } elseif ($prevEnd !== null && $date >= $prevStart->toDateString() && $date <= $prevEnd) {
                $prevConversations += (int) $row->convs;
                $prevUnanswered += (int) $row->unanswered;
            }
        }
        if ($prevEnd === null) {
            $prevConversations = null;
            $prevUnanswered = null;
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
            'intentTotals',
            'profileGaps',
            'monthConversations',
            'prevConversations',
            'prevUnanswered',
            'comparedDays',
            'plan',
            'walletMonthDelta',
            'walletMonthCount',
            'primaryDomain',
        );
    }
}
