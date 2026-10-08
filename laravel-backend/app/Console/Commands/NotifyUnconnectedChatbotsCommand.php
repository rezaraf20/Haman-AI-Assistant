<?php
namespace App\Console\Commands;

use App\Models\{ChatbotIndexEntry, User};
use App\Services\SmsService;
use App\Support\{PlatformActivity, Settings};
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * khonehrangi.ir's plugin never once connected after signup, and nobody —
 * not the merchant, not the platform — ever found out, because the only
 * mechanism that would have said so (OnboardingChecklist) only renders on a
 * portal login the merchant never made. This is the active counterpart:
 * it finds a chatbot and reaches out, rather than waiting to be looked at.
 *
 * SMS, not email, by explicit instruction (2026-10-08): email deliverability
 * was never confirmed to reach a real inbox (see signup.require_email_
 * verification's own docblock), while SMS already demonstrably works (OTP
 * login). Switch this to email once a real send is confirmed received.
 *
 * "Never connected" here means literally zero plugin authentication ever
 * (api_keys.last_used_at IS NULL for every key bound to the chatbot) — the
 * narrower, stricter signal than NeverConnectedChatbots' admin widget, which
 * also flags a chatbot that connected once and then went quiet. A chatbot
 * that already connected at least once doesn't need this nudge again; once
 * last_used_at is set, this query naturally stops matching it, so a
 * connection made between the first and second alert silently cancels the
 * second one with no extra bookkeeping.
 */
class NotifyUnconnectedChatbotsCommand extends Command {
    protected $signature = 'haman:notify-unconnected-chatbots';
    protected $description = 'SMS the customer and notify platform admins when a chatbot still has no plugin connection past the configured thresholds (first alert, then a repeat)';

    public function handle(SmsService $sms): void {
        $firstHours = (int) Settings::get('limits.connection_alert_first_hours');
        $secondDays = (int) Settings::get('limits.connection_alert_second_days');
        $firstCutoff = now()->subHours($firstHours);
        $secondCutoff = now()->subDays($secondDays);

        // Public schema only — api_keys and chatbot_index both live there,
        // so this needs none of FailedSyncsTable's per-tenant-schema
        // search_path switching.
        $neverConnected = DB::table('chatbot_index as ci')
            ->where('ci.is_active', true)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('api_keys as ak')
                    ->whereColumn('ak.chatbot_id', 'ci.chatbot_id')
                    ->whereNotNull('ak.last_used_at');
            })
            ->select('ci.chatbot_id', 'ci.tenant_id', 'ci.name', 'ci.created_at',
                'ci.connection_alert_first_sent_at', 'ci.connection_alert_second_sent_at')
            ->get();

        $admins = User::where('is_platform_admin', true)->get();
        $firstSent = 0;
        $secondSent = 0;

        foreach ($neverConnected as $row) {
            $createdAt = \Illuminate\Support\Carbon::parse($row->created_at);
            $tenant = \App\Models\Tenant::find($row->tenant_id);
            if (!$tenant) continue;

            $isSecond = $row->connection_alert_first_sent_at !== null
                && $row->connection_alert_second_sent_at === null
                && $createdAt->lte($secondCutoff);
            $isFirst = $row->connection_alert_first_sent_at === null
                && $createdAt->lte($firstCutoff);

            if (!$isFirst && !$isSecond) continue;

            $chatbotName = $row->name ?: $tenant->name;
            $column = $isSecond ? 'connection_alert_second_sent_at' : 'connection_alert_first_sent_at';

            if ($tenant->phone) {
                $sms->sendPlainMessage($tenant->phone, $isSecond
                    ? "هامان AI: چت‌بات «{$chatbotName}» شما هنوز به افزونه‌ی وردپرس وصل نشده — یک هفته از ساخت آن گذشته. لطفاً کلید API را در تنظیمات افزونه وارد کرده و «تست اتصال» را بزنید، یا با پشتیبانی تماس بگیرید."
                    : "هامان AI: چت‌بات «{$chatbotName}» شما هنوز به افزونه‌ی وردپرس وصل نشده. برای راه‌اندازی، کلید API را از پورتال کپی کرده و در تنظیمات افزونه وارد کنید، سپس «تست اتصال» را بزنید."
                );
            }

            foreach ($admins as $admin) {
                Notification::make()
                    ->title(__('dashboard.connection_alert_admin_title', ['tenant' => $tenant->name]))
                    ->body(__($isSecond ? 'dashboard.connection_alert_admin_body_second' : 'dashboard.connection_alert_admin_body_first', [
                        'chatbot' => $chatbotName,
                        'days'    => (int) $createdAt->diffInDays(now()),
                    ]))
                    ->warning()
                    ->sendToDatabase($admin);
            }

            ChatbotIndexEntry::where('chatbot_id', $row->chatbot_id)->update([$column => now()]);

            PlatformActivity::record(
                action: 'connection_alert_sent',
                tenantId: $tenant->id,
                subjectType: 'chatbot',
                subjectId: $row->chatbot_id,
                after: ['stage' => $isSecond ? 'second' : 'first', 'days_since_creation' => (int) $createdAt->diffInDays(now())],
            );

            $isSecond ? $secondSent++ : $firstSent++;
        }

        $this->line("Unconnected-chatbot alerts: {$firstSent} first, {$secondSent} repeat (day {$secondDays}).");
    }
}
