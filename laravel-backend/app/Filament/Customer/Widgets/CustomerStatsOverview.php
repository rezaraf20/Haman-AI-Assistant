<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Money, Numbers, CustomerOnboarding};
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\{DB, Cache};

// Plain Widget (not StatsOverviewWidget) so the token-remaining card can
// render a real progress bar, which Stat::make() has no slot for — the
// customer explicitly asked for a bar, not just a number.
class CustomerStatsOverview extends Widget {
    protected static string $view = 'filament.customer.widgets.customer-stats-overview';
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getData(): array {
        $tenant = auth()->user()->tenant->load('plan');

        return Cache::remember("dashboard:customer:stats:{$tenant->id}", 300, function () use ($tenant) {
            DB::statement("SET search_path TO {$tenant->schema_name}, public");
            $monthRow = DB::table('analytics_daily')
                ->where('date', '>=', now()->startOfMonth()->toDateString())
                ->selectRaw('COALESCE(SUM(user_messages), 0) as questions, COALESCE(SUM(unanswered_count), 0) as unanswered')
                ->first();
            DB::statement('SET search_path TO public');

            $limit = $tenant->plan?->max_tokens_monthly;
            $used  = $tenant->usage_tokens_current;
            $pct   = $limit ? min(100, round(($used / max($limit, 1)) * 100)) : null;

            return [
                'questions_month' => (int) $monthRow->questions,
                'unanswered'      => (int) $monthRow->unanswered,
                'wallet_toman'    => $tenant->wallet_balance_toman,
                'tokens_used'     => $used,
                'tokens_limit'    => $limit,
                'tokens_pct'      => $pct,
                'bonus_tokens'    => $tenant->bonus_tokens,
            ];
        });
    }

    public function fmt(int $n): string { return Numbers::format($n); }
    public function toman(int $n): string { return Money::toman($n); }
}
