<?php
namespace App\Support;

use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Support\Str;

/**
 * The record of what platform users did.
 *
 * Support has real access to real customers' phone numbers, and access that
 * nobody can review afterwards is indistinguishable from no controls at all.
 * So every deliberate act lands here: who, what, to whom, why, and — for a
 * change — the values before and after.
 *
 * Append-only by construction. This class has no update or delete method,
 * nothing else in the application writes to the table, and the retention
 * command only ever blanks the two payload columns. See ActivityLog, the
 * admin page, which likewise offers no delete action of any kind.
 *
 * Never throws. A failed write must not stop support doing their job, but
 * it is logged, so a silently broken trail does not go unnoticed.
 */
class PlatformActivity
{
    /** The only reasons a staff member may give for reading a conversation. */
    public const REASONS = ['ticket_review', 'error_report', 'customer_request'];

    /**
     * Every action this system records, grouped for the log page's filter.
     * A new call site adds its action here so the filter stays complete and
     * the page can label it; an unlisted action still records, it just shows
     * its raw name.
     */
    public const ACTIONS = [
        'access' => ['login', 'logout', 'login_failed'],
        'conversations' => ['conversation_list_opened', 'conversation_viewed', 'contact_revealed'],
        'settings' => ['chatbot_settings_changed', 'tenant_settings_changed', 'site_content_changed', 'brand_changed'],
        'operations' => ['cache_cleared', 'webhook_secret_rotated', 'sync_triggered'],
        'tickets' => ['ticket_replied', 'ticket_status_changed'],
        'staff' => ['staff_created', 'staff_updated', 'staff_activated', 'staff_deactivated'],
        'platform' => ['platform_settings_changed', 'plan_changed'],
    ];

    public static function allActions(): array
    {
        return array_merge(...array_values(self::ACTIONS));
    }

    /**
     * $before and $after hold only the fields that changed — see diff().
     * Everything else is context: which tenant, which object, and the reason
     * the operator gave before being shown anything.
     */
    public static function record(
        string $action,
        ?string $tenantId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        mixed $actor = null,
    ): void {
        try {
            $user = $actor ?? auth()->user();
            if (!$user) return;

            DB::table('platform_activity_log')->insert([
                'id'            => (string) Str::uuid(),
                'user_id'       => $user->id,
                'user_email'    => $user->email,
                'platform_role' => $user->platform_role,
                'action'        => $action,
                'tenant_id'     => $tenantId,
                'subject_type'  => $subjectType,
                'subject_id'    => $subjectId,
                'reason'        => in_array($reason, self::REASONS, true) ? $reason : null,
                'before'        => self::encode($before),
                'after'         => self::encode($after),
                'ip'            => self::requestValue(fn () => request()->ip()),
                'user_agent'    => Str::limit((string) self::requestValue(fn () => request()->userAgent()), 490, ''),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('PlatformActivity write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    /**
     * Splits a change into the two payload columns, keeping only the fields
     * that actually differ. A diff of everything would bury the one line
     * that matters, and the retention rule blanks these two columns alone.
     *
     * @return array{0: array, 1: array} [before, after]
     */
    public static function diff(array $before, array $after): array
    {
        $wasChanged = [];
        $nowIs = [];

        foreach ($after as $key => $newValue) {
            $old = self::scalarise($before[$key] ?? null);
            $new = self::scalarise($newValue);
            if (self::same($old, $new)) continue;

            $wasChanged[$key] = $old;
            $nowIs[$key]      = $new;
        }

        return [$wasChanged, $nowIs];
    }

    /**
     * Where this is deliberately loose: a form posts the string "12" for a
     * column holding the integer 12, and recording that as a change would
     * fill the log with edits nobody made. Everywhere else it errs towards
     * recording — null against false is kept, because "was never set" and
     * "was switched off" are different facts about a customer's account.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === $b) return true;
        if (is_numeric($a) && is_numeric($b)) return (float) $a === (float) $b;
        if (is_bool($a) || is_bool($b)) {
            return $a !== null && $b !== null && (bool) $a === (bool) $b;
        }
        return false;
    }

    /** True when diff() found nothing worth recording. */
    public static function isEmptyDiff(array $diff): bool
    {
        return $diff[0] === [] && $diff[1] === [];
    }

    /** 09121234567 -> 0912***4567, a@b.com -> a***@b.com */
    public static function mask(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') return '';

        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);
            return mb_substr($local, 0, 1) . '***@' . $domain;
        }

        $len = mb_strlen($value);
        if ($len <= 4) return str_repeat('*', $len);
        return mb_substr($value, 0, 4) . '***' . mb_substr($value, -4);
    }

    private static function encode(?array $payload): ?string
    {
        return $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
    }

    /** Dates and models would otherwise serialise into something unreadable. */
    private static function scalarise(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d H:i:s');
        if (is_scalar($value) || $value === null || is_array($value)) return $value;
        if ($value instanceof \BackedEnum) return $value->value;
        if (is_object($value) && method_exists($value, '__toString')) return (string) $value;
        return null;
    }

    /**
     * A console or queue context has no real request behind it, and asking
     * for one there yields an empty Request rather than an error — so this
     * only needs to survive the read, not predict the context. Deliberately
     * not gated on runningInConsole(): that is also true under PHPUnit,
     * which would make the capture untestable.
     */
    private static function requestValue(callable $read): ?string
    {
        try {
            $value = $read();
            return $value === '' ? null : $value;
        } catch (\Throwable) {
            return null;
        }
    }
}
