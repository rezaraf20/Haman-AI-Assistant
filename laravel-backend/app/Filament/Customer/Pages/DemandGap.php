<?php
namespace App\Filament\Customer\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * The "demand gap" report: what visitors actually asked this month, how much
 * of it the bot genuinely couldn't answer (messages.is_unanswered, set by
 * the Python RAG service when nothing cleared the chatbot's own
 * retrieval_threshold — see rag_service.py), and the most frequent questions
 * overall. The unanswered list doubles as the practical "what's missing from
 * my catalog" signal: this app has no separate product-name-extraction step,
 * so a tenant reads the raw unanswered questions themselves to spot the
 * pattern (that's the honest scope of this feature, not a claim of NLP entity
 * detection that doesn't exist).
 */
class DemandGap extends Page {
    protected static string $view = 'filament.customer.pages.demand-gap';
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    public static function getNavigationLabel(): string { return __('chatbot.demand_gap_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('chatbot.demand_gap_nav'); }

    public function getDashboardData(): array {
        $tenant = auth()->user()->tenant;
        $schema = $tenant->schema_name;
        $start  = now()->startOfMonth();
        $end    = now()->endOfMonth();

        DB::statement("SET search_path TO {$schema}, public");

        $totalQuestions = DB::table('messages')
            ->where('role', 'user')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $unansweredCount = DB::table('messages')
            ->where('role', 'assistant')
            ->where('is_unanswered', true)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Pairs each unanswered assistant reply with the user question that
        // triggered it (the immediately preceding user message in the same
        // conversation — ChatService::sendMessage() always creates the user
        // row before the assistant row, so this ordering is reliable),
        // grouped by exact question text so a repeated question surfaces as
        // one row with a count instead of N identical rows.
        $topUnanswered = DB::select("
            SELECT u.content AS question, COUNT(*) AS cnt
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
              AND a.created_at BETWEEN ? AND ?
            GROUP BY u.content
            ORDER BY cnt DESC
            LIMIT 20
        ", [$start, $end]);

        $topQuestionsOverall = DB::table('messages')
            ->select('content', DB::raw('COUNT(*) as cnt'))
            ->where('role', 'user')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('content')
            ->orderByDesc('cnt')
            ->limit(20)
            ->get();

        DB::statement('SET search_path TO public');

        return [
            'total_questions'   => $totalQuestions,
            'unanswered_count'  => $unansweredCount,
            'unanswered_pct'    => $totalQuestions > 0 ? round($unansweredCount / $totalQuestions * 100, 1) : 0.0,
            'top_unanswered'    => $topUnanswered,
            'top_questions'     => $topQuestionsOverall,
            'range_start'       => $start,
            'range_end'         => $end,
        ];
    }
}
