<?php
namespace App\Filament\Customer\Pages;

use Filament\Pages\Page;

class Usage extends Page {
    protected static string $view = 'filament.customer.pages.usage';
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    public static function getNavigationLabel(): string { return __('chatbot.usage_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('chatbot.usage_nav'); }

    // Same shape as TenantController::usage() (routes/api.php's Sanctum
    // dashboard API) — not calling that endpoint itself since this Livewire
    // page already has direct model access in the same request/session.
    public function getUsageData(): array {
        $tenant = auth()->user()->tenant->load('plan');
        return [
            'tokens' => [
                'used'  => $tenant->usage_tokens_current,
                'limit' => $tenant->plan?->max_tokens_monthly,
            ],
            'messages' => [
                'used'  => $tenant->usage_messages_current,
                'limit' => $tenant->plan?->max_messages_monthly,
            ],
            'bonus_tokens' => $tenant->bonus_tokens,
            'resets_at' => now()->endOfMonth(),
        ];
    }
}
