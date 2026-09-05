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
    public static function forTenant(Tenant $tenant): array {
        return Cache::remember("dashboard:customer:data:{$tenant->id}", 300, function () use ($tenant) {
            $chatbots = ChatbotIndexEntry::where('tenant_id', $tenant->id)->get();
            $maxTokensMonthly = $tenant->plan?->max_tokens_monthly;

            DB::statement("SET search_path TO {$tenant->schema_name}, public");

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

            DB::statement('SET search_path TO public');

            return compact(
                'chatbotStatuses',
                'dailyRows',
                'monthQuestions',
                'monthUnanswered',
                'recentUnanswered',
                'topTopics',
                'maxTokensMonthly',
            );
        });
    }
}
