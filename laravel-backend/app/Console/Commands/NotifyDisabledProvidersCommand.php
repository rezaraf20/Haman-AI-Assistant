<?php
namespace App\Console\Commands;

use App\Models\{LlmProviderProfile, User};
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

// Python's llm_provider_service.py::record_outcome() auto-disables a
// provider (is_active=false, disabled_reason set) after
// AUTO_DISABLE_THRESHOLD consecutive failures — it can flip that flag
// directly, but has no access to Filament's notification classes to alert
// anyone about it. This is the other half: find disable events that
// haven't been announced yet and send a real Filament database
// notification (bell icon, AdminPanelProvider's ->databaseNotifications())
// to every platform admin, then mark them announced so a later run doesn't
// re-notify for the same event.
class NotifyDisabledProvidersCommand extends Command {
    protected $signature = 'hamman:notify-disabled-providers';
    protected $description = 'Alert platform admins about LLM provider profiles auto-disabled due to repeated failures';

    public function handle(): void {
        $profiles = LlmProviderProfile::whereNotNull('disabled_reason')
            ->whereNull('disabled_notified_at')
            ->get();

        if ($profiles->isEmpty()) {
            return;
        }

        $admins = User::where('is_platform_admin', true)->get();

        foreach ($profiles as $profile) {
            foreach ($admins as $admin) {
                Notification::make()
                    ->title("LLM provider disabled: {$profile->name}")
                    ->body($profile->disabled_reason)
                    ->danger()
                    ->sendToDatabase($admin);
            }
            $profile->update(['disabled_notified_at' => now()]);
            $this->line("Notified {$admins->count()} admin(s) about '{$profile->name}'");
        }
    }
}
