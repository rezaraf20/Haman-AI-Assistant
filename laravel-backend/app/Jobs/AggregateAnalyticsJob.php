<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\Tenant\{Chatbot,AnalyticsDaily,Message,Conversation};
use Illuminate\Support\Facades\DB;

class AggregateAnalyticsJob implements ShouldQueue {
    use Dispatchable, Queueable;
    public int $timeout = 300;

    // Optional so the scheduled run (routes/console.php's hamman:aggregate-
    // analytics, no args) keeps aggregating "yesterday" as before; a
    // specific date is only ever passed for backfilling history that
    // predates this job actually being scheduled (see hamman:backfill-
    // analytics).
    public function __construct(private ?string $date = null) {}

    public function handle(): void {
        $date = $this->date ?? now()->subDay()->toDateString();

        $platformTotals = ['total_messages' => 0, 'total_conversations' => 0, 'total_tokens' => 0, 'cost_toman' => 0.0, 'unanswered_count' => 0];
        $tenantsWithActivity = 0;

        \App\Models\Tenant::active()->with('plan')->chunk(20, function ($tenants) use ($date, &$platformTotals, &$tenantsWithActivity) {
            foreach ($tenants as $tenant) {
                DB::statement("SET search_path TO {$tenant->schema_name},public");
                $chatbots = Chatbot::active()->get();
                $tenantHadActivity = false;

                foreach ($chatbots as $chatbot) {
                    $msgs  = Message::where('chatbot_id', $chatbot->id)->whereDate('created_at', $date)->get();
                    $convs = Conversation::where('chatbot_id', $chatbot->id)->whereDate('started_at', $date)->count();
                    if ($msgs->isEmpty()) continue;
                    $tenantHadActivity = true;

                    $costToman       = (float) $msgs->sum('cost_toman');
                    $unansweredCount = $msgs->where('role', 'assistant')->where('is_unanswered', true)->count();

                    AnalyticsDaily::updateOrCreate(['chatbot_id' => $chatbot->id, 'date' => $date], [
                        'total_conversations'     => $convs,
                        'total_messages'          => $msgs->count(),
                        'user_messages'           => $msgs->where('role', 'user')->count(),
                        'assistant_messages'      => $msgs->where('role', 'assistant')->count(),
                        'total_tokens'            => $msgs->sum('total_tokens'),
                        'prompt_tokens'           => $msgs->sum('prompt_tokens'),
                        'completion_tokens'       => $msgs->sum('completion_tokens'),
                        // The actual per-LLM-call cost (see LlmProviderProfile's
                        // admin-entered per-1M-token prices) — what ProfitMargin.php
                        // and the admin dashboard's cost/margin cards read instead
                        // of summing raw messages on every page load.
                        'cost_toman'              => $costToman,
                        'avg_response_latency_ms' => (int) $msgs->where('role', 'assistant')->avg('latency_ms'),
                        'fallback_count'          => $msgs->where('is_fallback', true)->count(),
                        // Set by the Python RAG service when nothing cleared the
                        // chatbot's retrieval/rerank threshold — the demand-gap /
                        // product-health signal, pre-aggregated here instead of
                        // scanned from messages on every dashboard load.
                        'unanswered_count'        => $unansweredCount,
                        'updated_at'              => now(),
                    ]);

                    $platformTotals['total_messages']      += $msgs->count();
                    $platformTotals['total_conversations'] += $convs;
                    $platformTotals['total_tokens']        += $msgs->sum('total_tokens');
                    $platformTotals['cost_toman']          += $costToman;
                    $platformTotals['unanswered_count']    += $unansweredCount;
                }

                if ($tenantHadActivity) $tenantsWithActivity++;
                DB::statement("SET search_path TO public");
            }
        });

        // Revenue is a public-schema query on its own (wallet_transactions
        // isn't tenant-schema-scoped), computed once here rather than
        // per-tenant — a negative amount_toman is a debit *from* the
        // tenant's wallet, i.e. money the platform actually collected that
        // day (see ProfitMargin.php for the same convention).
        $revenueToman = (float) DB::table('wallet_transactions')
            ->where('status', 'completed')
            ->where('amount_toman', '<', 0)
            ->whereDate('created_at', $date)
            ->sum(DB::raw('-amount_toman'));

        DB::table('platform_daily_stats')->updateOrInsert(
            ['date' => $date],
            [
                'total_messages'      => $platformTotals['total_messages'],
                'total_conversations' => $platformTotals['total_conversations'],
                'total_tokens'        => $platformTotals['total_tokens'],
                'cost_toman'          => $platformTotals['cost_toman'],
                'revenue_toman'       => $revenueToman,
                'unanswered_count'    => $platformTotals['unanswered_count'],
                'active_tenants'      => $tenantsWithActivity,
                'updated_at'          => now(),
                'created_at'          => now(),
            ]
        );
    }
}
