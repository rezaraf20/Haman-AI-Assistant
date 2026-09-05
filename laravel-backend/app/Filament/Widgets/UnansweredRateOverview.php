<?php
namespace App\Filament\Widgets;

use App\Support\Numbers;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\{DB, Cache};

// Product-health signal, not a financial one — a rising unanswered rate
// across the whole platform means the RAG pipeline (or a lot of tenants'
// content) is falling short, regardless of how revenue/margin look.
class UnansweredRateOverview extends StatsOverviewWidget {
    protected static ?string $pollingInterval = null;
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = false;

    protected function getStats(): array {
        $row = Cache::remember('dashboard:admin:unanswered-rate', 300, function () {
            return DB::table('platform_daily_stats')
                ->where('date', '>=', now()->startOfMonth()->toDateString())
                ->selectRaw('COALESCE(SUM(unanswered_count), 0) as unanswered, COALESCE(SUM(total_messages), 0) as total')
                ->first();
        });

        $total = (int) $row->total;
        $rate = $total > 0 ? round(((int) $row->unanswered / $total) * 100, 1) : null;

        return [
            Stat::make(__('dashboard.admin_unanswered_rate'), $rate === null ? '—' : Numbers::format($rate, 1) . '%')
                ->description(__('dashboard.admin_unanswered_rate_desc'))
                ->icon('heroicon-o-question-mark-circle')
                ->color($rate === null ? 'gray' : ($rate >= 20 ? 'danger' : ($rate >= 10 ? 'warning' : 'success'))),
        ];
    }
}
