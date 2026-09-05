<?php
namespace App\Filament\Customer\Widgets;

use App\Support\{Jalali, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

class ChatbotStatusWidget extends Widget {
    protected static string $view = 'filament.customer.widgets.chatbot-status';
    protected int|string|array $columnSpan = 1;
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getRows(): array {
        $tenant = auth()->user()->tenant;
        $data = CustomerDashboardData::forTenant($tenant);

        return array_map(function (array $chatbot) {
            $status = match (true) {
                !$chatbot['is_active'] => 'suspended',
                $chatbot['sync_status'] === 'running' => 'syncing',
                $chatbot['sync_status'] === 'failed' => 'error',
                default => 'active',
            };

            return [
                'name' => $chatbot['name'],
                'status' => $status,
                'last_sync' => $chatbot['last_sync'],
            ];
        }, $data['chatbotStatuses']);
    }

    public function statusLabel(string $status): string {
        return match ($status) {
            'active' => __('dashboard.customer_chatbot_status_active'),
            'syncing' => __('dashboard.customer_chatbot_status_syncing'),
            'error' => __('dashboard.customer_chatbot_status_error'),
            'suspended' => __('dashboard.customer_chatbot_status_suspended'),
        };
    }

    public function statusColor(string $status): string {
        return match ($status) {
            'active' => 'success',
            'syncing' => 'info',
            'error' => 'danger',
            'suspended' => 'gray',
        };
    }

    public function lastSyncLabel(?string $when): string {
        return $when
            ? __('dashboard.customer_chatbot_status_last_sync', ['when' => Jalali::dateTime($when)])
            : __('dashboard.customer_chatbot_status_never_synced');
    }
}
