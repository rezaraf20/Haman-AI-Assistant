<?php
namespace App\Services;

use App\Models\Tenant\Chatbot;
use Illuminate\Support\Facades\{Http, Log, Mail};
use App\Support\MailSettings;

/**
 * Dispatches a merchant-facing alert (lead_captured / unanswered) through
 * whichever channels a chatbot's notification_settings has enabled for
 * that event, in priority order email -> telegram -> webhook — not a
 * failover chain (all enabled channels fire, independently), just the
 * order they were built in and are documented in.
 *
 * A channel with digest=true is skipped by send() entirely — see
 * sendDigest() / SendNotificationDigestsCommand, which reads
 * conversation_events directly for its summary instead of needing a
 * separate queue table.
 *
 * Best-effort like every other notification path in this app
 * (record_outcome(), NotifyDisabledProvidersCommand) — a failed send must
 * never break the chat response it's attached to.
 */
class NotificationService {
    public function send(Chatbot $chatbot, string $event, array $data): void {
        $settings = $chatbot->notification_settings ?? [];

        foreach (['email', 'telegram', 'webhook'] as $channel) {
            $config = $settings[$channel] ?? null;
            if (!$config || empty($config['enabled'])) continue;
            if (!in_array($event, $config['events'] ?? [], true)) continue;
            if (!empty($config['digest'])) continue; // batched later, not now

            $this->dispatch($channel, $config, $chatbot, $this->renderSubject($chatbot, $event), $this->renderText($chatbot, $event, $data), $event, $data);
        }
    }

    /** One rollup message per chatbot/channel/event, called by
     * SendNotificationDigestsCommand for channels configured with
     * digest=true — $rows is raw conversation_events rows (payload,
     * created_at) for that event/window, already fetched by the caller. */
    public function sendDigest(Chatbot $chatbot, string $channel, array $config, string $event, array $rows): void {
        $count = count($rows);
        $label = match ($event) {
            'lead_captured' => $count === 1 ? '1 new lead' : "{$count} new leads",
            'unanswered'    => $count === 1 ? '1 unanswered question' : "{$count} unanswered questions",
            default         => "{$count} {$event} event(s)",
        };
        $lines = array_map(function ($row) use ($event) {
            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            return match ($event) {
                'lead_captured' => "- {$payload['contact']} ({$payload['contact_type']}) — {$payload['reason']}",
                'unanswered'    => "- {$payload['query']}",
                default         => '- ' . json_encode($payload),
            };
        }, $rows);

        $subject = "{$chatbot->name}: {$label} in the last 24h";
        $text = "{$label} for {$chatbot->name} in the last 24 hours:\n\n" . implode("\n", $lines);

        $this->dispatch($channel, $config, $chatbot, $subject, $text, $event, ['count' => $count]);
    }

    /**
     * Whether a channel can run at all right now. Email needs platform-wide
     * SMTP credentials that no chatbot owner can supply; without them the
     * channel is dropped here rather than throwing inside sendEmail() where
     * the failure would only ever reach a log line.
     */
    public static function channelAvailable(string $channel): bool {
        return $channel === 'email' ? MailSettings::isUsable() : true;
    }

    private function dispatch(string $channel, array $config, Chatbot $chatbot, string $subject, string $text, string $event, array $data): void {
        if (!self::channelAvailable($channel)) {
            Log::info("NotificationService: {$channel} skipped for chatbot {$chatbot->id} ({$event}) — not configured platform-wide.");
            return;
        }

        try {
            match ($channel) {
                'email'    => $this->sendEmail($config, $subject, $text),
                'telegram' => $this->sendTelegram($config, $text),
                'webhook'  => $this->sendWebhook($config, $chatbot, $event, $data, $text),
            };
        } catch (\Throwable $e) {
            Log::warning("NotificationService: {$channel} send failed for chatbot {$chatbot->id} ({$event}): {$e->getMessage()}");
        }
    }

    private function sendEmail(array $config, string $subject, string $text): void {
        $address = $config['address'] ?? null;
        if (!$address) return;
        Mail::raw($text, function ($msg) use ($address, $subject) {
            $msg->to($address)->subject($subject);
        });
    }

    private function sendTelegram(array $config, string $text): void {
        $token = $config['bot_token'] ?? null;
        $chatId = $config['chat_id'] ?? null;
        if (!$token || !$chatId) return;
        Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text'    => $text,
        ]);
    }

    private function sendWebhook(array $config, Chatbot $chatbot, string $event, array $data, string $text): void {
        $url = $config['url'] ?? null;
        if (!$url) return;
        Http::timeout(10)->post($url, [
            'event'      => $event,
            'chatbot_id' => $chatbot->id,
            'chatbot'    => $chatbot->name,
            'summary'    => $text,
            'data'       => $data,
            'sent_at'    => now()->toIso8601String(),
        ]);
    }

    private function renderSubject(Chatbot $chatbot, string $event): string {
        return match ($event) {
            'lead_captured' => "New lead — {$chatbot->name}",
            'unanswered'    => "Unanswered question — {$chatbot->name}",
            'restock_waitlist' => "Back in stock — people were waiting — {$chatbot->name}",
            default         => "{$chatbot->name}: {$event}",
        };
    }

    private function renderText(Chatbot $chatbot, string $event, array $data): string {
        return match ($event) {
            'lead_captured' => sprintf(
                "New lead from %s\nContact: %s (%s)\nQuestion: %s",
                $chatbot->name, $data['contact'] ?? '-', $data['contact_type'] ?? '-', $data['question'] ?? '-'
            ),
            'unanswered' => sprintf(
                "Unanswered question on %s\nQuery: %s\nBest score: %s",
                $chatbot->name, $data['query'] ?? '-', $data['best_score'] ?? '-'
            ),
            // Tells the merchant who was waiting and hands them the numbers.
            // Deliberately does NOT text the customers: that is the shop's
            // call to make and their SMS bill to pay.
            'restock_waitlist' => sprintf(
                "%s is back in stock on %s.\n\n%d %s waiting:\n%s\n\nNothing has been sent to them — contact them from the Requests page when you're ready.",
                $data['item'] ?? '-', $chatbot->name,
                $data['count'] ?? 0,
                (($data['count'] ?? 0) === 1 ? 'person was' : 'people were'),
                implode("\n", array_map(fn ($c) => "- {$c}", $data['contacts'] ?? []))
            ),
            default => "{$chatbot->name}: {$event} — " . json_encode($data),
        };
    }
}
