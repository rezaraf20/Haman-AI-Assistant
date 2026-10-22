<?php
namespace App\Console\Commands;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

// Python's llm_provider_service.py::record_all_providers_failed() sets a
// Redis key when a single request exhausts every active profile in a
// chain — a real customer just got fallback_response/_default_error_response
// text instead of an actual answer. Python has no Filament access of its
// own (same split as NotifyDisabledProvidersCommand, for the same reason),
// so this is the other half: poll the key, notify once, then suppress
// re-notifying for COOLDOWN_SECONDS so a provider that keeps failing for an
// hour doesn't page an admin on every single poll — one notification per
// outage window, not per request.
//
// Uses Redis::connection('shared') (registered in
// App\Providers\AppServiceProvider, not config/database.php — see its own
// comment), never the bare Redis:: facade: the default connection's global
// key prefix (database.redis.options.prefix, Laravel's own stock formula
// from APP_NAME -- nothing this project set on purpose) is applied
// transparently on every command through it, so Redis::get() here read
// back null for a key that genuinely existed, silently, every time. Caught
// only because a live outage drill checked the database row the
// notification should have produced, not just this command's exit code.
class NotifyProviderOutageCommand extends Command {
    protected $signature   = 'haman:notify-provider-outage';
    protected $description = 'Alert platform admins when a chat request exhausted every active LLM provider';

    // ALERT_KEY crosses languages (Python writes it) and carries "shared:"
    // for exactly that reason — see SharedRedisKeyConventionTest. COOLDOWN_KEY
    // is Laravel-only (set and read only here), so it doesn't need the marker,
    // but shares the same connection for simplicity; there's no correctness
    // reason it couldn't live on the default connection instead.
    private const ALERT_KEY    = 'shared:haman:alerts:all_providers_failed_at';
    private const COOLDOWN_KEY = 'haman:alerts:all_providers_failed_notified';
    private const COOLDOWN_SECONDS = 1800;

    public function handle(): void {
        $redis = Redis::connection('shared');

        $raw = $redis->get(self::ALERT_KEY);
        if (!$raw) {
            return;
        }

        if ($redis->get(self::COOLDOWN_KEY)) {
            $this->line('All-providers-failed signal present but still within the notification cooldown — skipping.');
            return;
        }

        $data = json_decode($raw, true);
        $reason = is_array($data) ? ($data['reason'] ?? $raw) : $raw;
        $occurredAt = is_array($data) && isset($data['at'])
            ? \Carbon\Carbon::createFromTimestamp($data['at'])->toDateTimeString()
            : 'unknown time';

        $admins = User::where('is_platform_admin', true)->get();
        foreach ($admins as $admin) {
            Notification::make()
                ->title('All LLM providers failed for a chat request')
                ->body("At {$occurredAt}: {$reason}\n\nCustomers are getting the configured fallback message instead of a real answer until this clears.")
                ->danger()
                ->sendToDatabase($admin);
        }

        $redis->set(self::COOLDOWN_KEY, '1', 'EX', self::COOLDOWN_SECONDS);
        $this->line("Notified {$admins->count()} admin(s) about an all-providers-failed event ({$occurredAt}).");
    }
}
