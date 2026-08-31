<?php
namespace App\Filament\Customer\Widgets;

use App\Models\ChatbotIndexEntry;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

// Sits at the top of the customer panel's default Dashboard page — until now
// that page rendered completely blank (Filament's stock Dashboard with no
// widgets registered), so a logged-in customer had no single "how's my
// account doing" glance and had to click into Wallet/MyChatbots/Usage/Tickets
// separately just to check basics.
class AccountOverview extends StatsOverviewWidget {
    protected function getStats(): array {
        $tenant = auth()->user()->tenant->load('plan');
        $tokenLimit = $tenant->plan?->max_tokens_monthly;
        $tokenPct   = $tokenLimit ? min(100, round(($tenant->usage_tokens_current / max($tokenLimit, 1)) * 100)) : null;

        $chatbotCount = ChatbotIndexEntry::where('tenant_id', $tenant->id)->where('is_active', true)->count();
        $openTickets  = Ticket::where('tenant_id', $tenant->id)->where('status', 'answered')->count();

        return [
            Stat::make('موجودی کیف پول', number_format($tenant->wallet_balance_toman) . ' تومان')
                ->icon('heroicon-o-wallet')
                ->color('success'),

            Stat::make('چت‌بات‌های فعال', $chatbotCount)
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('primary'),

            Stat::make('مصرف توکن این ماه', $tokenPct !== null ? "{$tokenPct}%" : 'نامحدود')
                ->description($tokenLimit ? number_format($tenant->usage_tokens_current) . ' از ' . number_format($tokenLimit) : null)
                ->icon('heroicon-o-chart-bar')
                ->color($tokenPct !== null && $tokenPct >= 90 ? 'danger' : 'primary'),

            Stat::make('تیکت‌های در انتظار پاسخ شما', $openTickets)
                ->icon('heroicon-o-lifebuoy')
                ->color($openTickets > 0 ? 'warning' : 'success'),
        ];
    }
}
