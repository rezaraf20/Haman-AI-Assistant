<?php
namespace App\Filament\Customer\Widgets;

use App\Filament\Customer\Pages\MyChatbots;
use App\Support\{Jalali, Numbers, CustomerOnboarding, CustomerDashboardData};
use Filament\Widgets\Widget;

class ChatbotStatusWidget extends Widget {
    protected static string $view = 'filament.customer.widgets.chatbot-status';
    protected int|string|array $columnSpan = 1;
    protected static ?int $sort = 4;
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
                // Same "connected" signal the onboarding checklist and the
                // unconnected-chatbot e-mails use: a key bound to this
                // chatbot has authenticated at least once. Until then a
                // sync status means nothing — there's no plugin to sync.
                !$chatbot['plugin_connected'] => 'awaiting_plugin',
                // 'indexing': content was pulled, but EmbedDocumentJob
                // hasn't finished turning it into something the bot can
                // actually answer from yet — see SyncService::
                // finalizeSyncJob(). Shown as "syncing" too, not "active":
                // a merchant checking this right after a sync must not see
                // a green light before the bot genuinely knows the content.
                in_array($chatbot['sync_status'], ['running', 'indexing'], true) => 'syncing',
                in_array($chatbot['sync_status'], ['failed', 'indexed_with_errors'], true) => 'error',
                default => 'active',
            };

            return [
                'name' => $chatbot['name'],
                'status' => $status,
                'last_sync' => $chatbot['last_sync'],
                'documents' => Numbers::format($chatbot['document_count']),
            ];
        }, $data['chatbotStatuses']);
    }

    public function statusLabel(string $status): string {
        return match ($status) {
            'active' => __('dashboard.customer_chatbot_status_active'),
            'awaiting_plugin' => __('portal.chatbot_status_awaiting_plugin'),
            'syncing' => __('dashboard.customer_chatbot_status_syncing'),
            'error' => __('dashboard.customer_chatbot_status_error'),
            'suspended' => __('dashboard.customer_chatbot_status_suspended'),
        };
    }

    public function statusTone(string $status): string {
        return match ($status) {
            'active' => 'success',
            'awaiting_plugin' => 'warning',
            'syncing' => 'info',
            'error' => 'danger',
            'suspended' => 'neutral',
        };
    }

    public function lastSyncLabel(?string $when): string {
        return $when
            ? __('dashboard.customer_chatbot_status_last_sync', ['when' => Jalali::dateTime($when)])
            : __('dashboard.customer_chatbot_status_never_synced');
    }

    public function manageUrl(): string {
        return MyChatbots::getUrl();
    }
}
