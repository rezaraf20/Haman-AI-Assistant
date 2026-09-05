<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Numbers, CustomerOnboarding};
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\{DB, Cache};

// The one dashboard widget that genuinely can't be served from
// analytics_daily — a daily rollup has no per-question text to group by.
// Scoped to this month, limited to 5, and cached 5 minutes to bound the
// real cost of querying messages directly here.
class TopTopicsWidget extends Widget {
    protected static string $view = 'filament.customer.widgets.top-topics';
    protected int|string|array $columnSpan = 1;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getRows(): array {
        $tenant = auth()->user()->tenant;

        return Cache::remember("dashboard:customer:top-topics:{$tenant->id}", 300, function () use ($tenant) {
            DB::statement("SET search_path TO {$tenant->schema_name}, public");
            $rows = DB::table('messages')
                ->select('content', DB::raw('COUNT(*) as cnt'))
                ->where('role', 'user')
                ->where('created_at', '>=', now()->startOfMonth())
                ->groupBy('content')
                ->orderByDesc('cnt')
                ->limit(5)
                ->get();
            DB::statement('SET search_path TO public');
            return $rows;
        });
    }

    public function fmt(int $n): string { return Numbers::format($n); }
}
