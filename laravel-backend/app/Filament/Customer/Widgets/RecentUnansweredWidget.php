<?php
namespace App\Filament\Customer\Widgets;

use App\Filament\Customer\Pages\DemandGap;
use App\Support\CustomerOnboarding;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\{DB, Cache};

class RecentUnansweredWidget extends Widget {
    protected static string $view = 'filament.customer.widgets.recent-unanswered';
    protected int|string|array $columnSpan = 1;
    protected static bool $isLazy = false;

    public static function canView(): bool {
        $tenant = auth()->user()?->tenant;
        return $tenant && CustomerOnboarding::isComplete($tenant);
    }

    public function getRows(): array {
        $tenant = auth()->user()->tenant;

        return Cache::remember("dashboard:customer:recent-unanswered:{$tenant->id}", 300, function () use ($tenant) {
            DB::statement("SET search_path TO {$tenant->schema_name}, public");
            // Same pairing logic as DemandGap.php: the user question
            // immediately preceding each unanswered assistant reply in the
            // same conversation.
            $rows = DB::select("
                SELECT u.content AS question, a.created_at
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
                ORDER BY a.created_at DESC
                LIMIT 5
            ");
            DB::statement('SET search_path TO public');
            return $rows;
        });
    }

    public function demandGapUrl(): string {
        return DemandGap::getUrl();
    }
}
