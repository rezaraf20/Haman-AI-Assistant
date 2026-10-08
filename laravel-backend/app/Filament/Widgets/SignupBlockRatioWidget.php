<?php
namespace App\Filament\Widgets;

use App\Support\PlatformAccess;
use App\Models\{SignupBlock, Tenant};
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Without this, a broken trap fails silently (2026-10-08 request, made
 * right after turning the signup honeypot/timing checks from a tag into
 * an outright block): nobody would ever notice a false positive turning
 * away real customers — the merchant just never shows up, and nothing
 * anywhere says why. A rising block-to-signup ratio is the signal that
 * something is catching more than bots; see SignupRisk::blockReason().
 */
class SignupBlockRatioWidget extends StatsOverviewWidget {
    public static function canView(): bool { return PlatformAccess::allows('tenants_read'); }

    protected static ?string $pollingInterval = null;
    protected static bool $isLazy = false;

    private const WINDOW_DAYS = 30;

    protected function getStats(): array {
        $data = Cache::remember('dashboard:admin:signup-block-ratio', 300, function () {
            $since = now()->subDays(self::WINDOW_DAYS);

            $blocks = SignupBlock::where('created_at', '>=', $since)->count();
            $signups = Tenant::where('created_at', '>=', $since)->count();

            $total = $blocks + $signups;
            $ratio = $total > 0 ? round(($blocks / $total) * 100, 1) : null;

            return compact('blocks', 'signups', 'ratio');
        });

        // No principled "too high" number exists yet — this is the
        // starting point to watch and tune once real traffic shows what
        // a healthy ratio actually looks like for this platform.
        $color = $data['ratio'] === null ? 'gray' : ($data['ratio'] >= 20 ? 'danger' : ($data['ratio'] >= 5 ? 'warning' : 'success'));

        return [
            Stat::make(__('dashboard.admin_stats_signup_blocks'), (string) $data['blocks'])
                ->description(__('dashboard.admin_stats_signup_blocks_desc', ['days' => self::WINDOW_DAYS]))
                ->icon('heroicon-o-shield-exclamation')
                ->color($data['blocks'] > 0 ? 'warning' : 'success'),

            Stat::make(__('dashboard.admin_stats_signup_block_ratio'), $data['ratio'] === null ? '—' : $data['ratio'] . '%')
                ->description(__('dashboard.admin_stats_signup_block_ratio_desc'))
                ->icon('heroicon-o-scale')
                ->color($color),
        ];
    }
}
