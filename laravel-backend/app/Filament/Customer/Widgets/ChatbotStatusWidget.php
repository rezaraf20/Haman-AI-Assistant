<?php
namespace App\Filament\Customer\Widgets;

use App\Models\ChatbotIndexEntry;
use App\Support\{Jalali, CustomerOnboarding};
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\{DB, Cache};

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

        return Cache::remember("dashboard:customer:chatbot-status:{$tenant->id}", 300, function () use ($tenant) {
            $chatbots = ChatbotIndexEntry::where('tenant_id', $tenant->id)->get();
            if ($chatbots->isEmpty()) return [];

            DB::statement("SET search_path TO {$tenant->schema_name}, public");
            $rows = [];
            foreach ($chatbots as $chatbot) {
                $latestSync = DB::table('sync_jobs')
                    ->where('chatbot_id', $chatbot->chatbot_id)
                    ->orderByDesc('created_at')
                    ->first(['status', 'created_at']);

                $status = match (true) {
                    !$chatbot->is_active => 'suspended',
                    $latestSync?->status === 'running' => 'syncing',
                    $latestSync?->status === 'failed' => 'error',
                    default => 'active',
                };

                $rows[] = [
                    'name' => $chatbot->name ?: '—',
                    'status' => $status,
                    'last_sync' => $latestSync?->created_at,
                ];
            }
            DB::statement('SET search_path TO public');

            return $rows;
        });
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
