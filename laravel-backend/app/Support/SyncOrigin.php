<?php
namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TEMPORARY — remove this class and its three call sites (ChatbotController::
 * updateSyncSettings, SyncController::clearIndex, SyncController::
 * handleWebhook) once the permanent constraint holds:
 *
 *     one API key = one chatbot = one domain
 *
 * With that in place the key itself identifies the site, the User-Agent is
 * irrelevant, and keeping this alongside would leave two parallel
 * mechanisms deciding the same thing. Do not extend this guard; finish the
 * constraint instead. (The sync_domain_alerts table can go with it.)
 *
 * Which WordPress site a plugin request came from, and whether that site
 * may make destructive changes to a chatbot.
 *
 * Found live: two sites (hamantech.ir, khonehrangi.ir) were connected to one
 * chatbot. The platform treats the plugin's sync settings as authoritative,
 * so whichever site saved last archived the other's documents — the types
 * it doesn't have were "switched off". Archiving is not reversible from the
 * owner's side, so every request that can remove or overwrite something now
 * has to come from the chatbot's own registered domain:
 *
 *   - PUT chatbots/{id}/sync-settings  (overwrites settings, archives types)
 *   - POST sync/clear                  (deletes every synced document)
 *   - webhook page.deleted / product.deleted (archives one document)
 *
 * Adding and updating content is not gated — that cannot lose anything.
 * The customer portal's own Sync Settings page is not gated either: that
 * is the owner acting directly, not a site acting on their behalf.
 *
 * The site: X-Haman-Site when a plugin sends it, otherwise WordPress's own
 * default User-Agent for wp_remote_request() — "WordPress/<ver>; <home url>"
 * — which every plugin version already sends, so this works for the sites
 * that are installed today without waiting for them to update. Neither is
 * proof of identity (the caller already holds a valid API key; this guards
 * against a misconfiguration, not an attacker with the key), so the rule
 * fails closed: an unknown site, or a chatbot with no registered domain,
 * is never allowed to archive.
 */
class SyncOrigin
{
    /** The normalised host the request says it came from, or null. */
    public static function fromRequest(Request $request): ?string
    {
        $declared = trim((string) $request->header('X-Haman-Site', ''));
        if ($declared !== '') {
            return DomainNormalizer::normalize($declared);
        }
        if (preg_match('#^WordPress/[^;]*;\s*(\S+)#', (string) $request->userAgent(), $m)) {
            return DomainNormalizer::normalize($m[1]);
        }
        return null;
    }

    /** chatbot_index.primary_domain, normalised — the same value the widget's ValidateChatbotDomain checks. */
    public static function registeredDomain(string $chatbotId): ?string
    {
        return DomainNormalizer::normalize(
            DB::table('public.chatbot_index')->where('chatbot_id', $chatbotId)->value('primary_domain')
        );
    }

    /**
     * Whether this request may archive, delete or overwrite for this chatbot.
     * When it may not, the refusal is recorded (see record()) and the
     * returned array carries what to tell the caller.
     *
     * @return array{allowed: bool, origin: ?string, registered: ?string, message: ?string}
     */
    public static function authorise(Request $request, string $chatbotId, string $action): array
    {
        $origin = self::fromRequest($request);
        $registered = self::registeredDomain($chatbotId);

        if ($origin !== null && $registered !== null && $origin === $registered) {
            return ['allowed' => true, 'origin' => $origin, 'registered' => $registered, 'message' => null];
        }

        self::record($chatbotId, $origin, $registered, $action);

        return [
            'allowed' => false,
            'origin' => $origin,
            'registered' => $registered,
            'message' => self::message($origin, $registered),
        ];
    }

    /**
     * Shown by the plugin as-is (it displays the API's `error`). The site's
     * language isn't known on this side, so both languages, Persian first.
     */
    public static function message(?string $origin, ?string $registered): string
    {
        $fa = __('sync_origin.refused', [
            'origin' => $origin ?? __('sync_origin.unknown_site', [], 'fa'),
            'registered' => $registered ?? __('sync_origin.no_domain', [], 'fa'),
        ], 'fa');
        $en = __('sync_origin.refused', [
            'origin' => $origin ?? __('sync_origin.unknown_site', [], 'en'),
            'registered' => $registered ?? __('sync_origin.no_domain', [], 'en'),
        ], 'en');
        return $fa . ' / ' . $en;
    }

    /**
     * One row per (chatbot, site, action); a repeat only bumps the count.
     * The platform activity log gets an entry the first time, and again if
     * it has been quiet for a day — enough to notice, not a flood from a
     * site that retries on every save.
     */
    private static function record(string $chatbotId, ?string $origin, ?string $registered, string $action): void
    {
        $tenantId = DB::table('public.chatbot_index')->where('chatbot_id', $chatbotId)->value('tenant_id');
        if (!$tenantId) return;

        $key = ['chatbot_id' => $chatbotId, 'origin_domain' => $origin ?? '', 'action' => $action];
        $existing = DB::table('public.sync_domain_alerts')->where($key)->first();

        if ($existing) {
            DB::table('public.sync_domain_alerts')->where('id', $existing->id)->update([
                'occurrences' => $existing->occurrences + 1,
                'registered_domain' => $registered,
                'last_seen_at' => now(),
            ]);
            $notify = \Illuminate\Support\Carbon::parse($existing->last_seen_at)->diffInHours(now()) >= 24; // Carbon 3: signed, earlier→later
        } else {
            DB::table('public.sync_domain_alerts')->insert($key + [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'registered_domain' => $registered,
                'occurrences' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
            $notify = true;
        }

        if ($notify) {
            PlatformActivity::record(
                'sync_domain_mismatch', $tenantId, 'chatbot', $chatbotId, null, null,
                ['origin_domain' => $origin, 'registered_domain' => $registered, 'refused' => $action],
            );
        }
    }

    /** Recent refusals for one chatbot, newest first — what the portal shows its owner. */
    public static function recentFor(string $chatbotId, int $days = 30): array
    {
        return DB::table('public.sync_domain_alerts')
            ->where('chatbot_id', $chatbotId)
            ->where('last_seen_at', '>=', now()->subDays($days))
            ->orderByDesc('last_seen_at')
            ->get()
            ->all();
    }
}
