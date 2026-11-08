<?php
namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Widget requests refused because the page they came from is not the
 * chatbot's registered domain (ValidateChatbotDomain). One row per
 * (chatbot, origin) with a counter, so the owner sees "khonehrangi.ir —
 * 140 times, last at …" in the portal instead of guessing why a site's
 * widget is silent, and the platform admin sees it in the activity log
 * (first time, then at most daily per site).
 *
 * The origin is attacker-controlled (anyone can send any Origin), so the
 * number of distinct origins kept per chatbot is capped; past the cap new
 * origins are still refused, just not stored.
 */
class DomainRejections
{
    public const MAX_ORIGINS_PER_CHATBOT = 50;

    public static function record(object $index, string $origin): void
    {
        try {
            $key = ['chatbot_id' => $index->chatbot_id, 'origin_domain' => Str::limit($origin, 250, '')];
            $existing = DB::table('public.domain_rejections')->where($key)->first();

            if ($existing) {
                DB::table('public.domain_rejections')->where('id', $existing->id)->update([
                    'occurrences' => $existing->occurrences + 1,
                    'last_seen_at' => now(),
                ]);
                $notify = Carbon::parse($existing->last_seen_at)->diffInHours(now()) >= 24;
            } else {
                if (DB::table('public.domain_rejections')->where('chatbot_id', $index->chatbot_id)->count() >= self::MAX_ORIGINS_PER_CHATBOT) {
                    return;
                }
                DB::table('public.domain_rejections')->insert($key + [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $index->tenant_id,
                    'occurrences' => 1,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);
                $notify = true;
            }

            if ($notify) {
                PlatformActivity::record(
                    'widget_domain_rejected', $index->tenant_id, 'chatbot', $index->chatbot_id, null, null,
                    ['origin_domain' => $origin, 'registered_domain' => $index->primary_domain],
                );
            }
        } catch (\Throwable $e) {
            // Recording must never turn a clean 403 into a 500.
            report($e);
        }
    }

    /** Recent rejections for one chatbot, newest first. */
    public static function recentFor(string $chatbotId, int $days = 30): array
    {
        return DB::table('public.domain_rejections')
            ->where('chatbot_id', $chatbotId)
            ->where('last_seen_at', '>=', now()->subDays($days))
            ->orderByDesc('last_seen_at')
            ->limit(10)
            ->get()
            ->all();
    }
}
