<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\Chatbot;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};

// NotificationService::send() only ever fires a channel configured with
// digest=false, immediately, inline in the chat request. A channel with
// digest=true instead waits for this daily run, which reads the real
// conversation_events history directly (no separate pending-notifications
// queue table needed — the events already carry everything a summary
// needs) and sends one rollup per chatbot/channel/event instead of one
// message per occurrence.
class SendNotificationDigestsCommand extends Command {
    protected $signature   = 'haman:send-notification-digests';
    protected $description = 'Send one daily summary per chatbot/channel for events configured with digest mode, instead of one alert per occurrence';

    public function handle(NotificationService $notifications): void {
        $since = now()->subDay();
        $sent = 0;

        Tenant::whereNotNull('schema_name')->chunk(20, function ($tenants) use ($notifications, $since, &$sent) {
            foreach ($tenants as $tenant) {
                try {
                    DB::statement("SET search_path TO {$tenant->schema_name}, public");
                    $chatbots = Chatbot::whereRaw("notification_settings != '{}'::jsonb")->get();

                    foreach ($chatbots as $chatbot) {
                        foreach (['email', 'telegram', 'webhook'] as $channel) {
                            $config = $chatbot->notification_settings[$channel] ?? null;
                            if (!$config || empty($config['enabled']) || empty($config['digest'])) continue;

                            foreach ($config['events'] ?? [] as $event) {
                                $rows = DB::table('conversation_events')
                                    ->where('chatbot_id', $chatbot->id)
                                    ->where('event_type', $event)
                                    ->where('created_at', '>=', $since)
                                    ->get(['payload', 'created_at']);
                                if ($rows->isEmpty()) continue;

                                $notifications->sendDigest($chatbot, $channel, $config, $event, $rows->toArray());
                                $sent++;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("haman:send-notification-digests: skipping tenant {$tenant->id} ({$tenant->schema_name}) — {$e->getMessage()}");
                } finally {
                    DB::statement('SET search_path TO public');
                }
            }
        });

        $this->info("Sent {$sent} digest notification(s).");
    }
}
