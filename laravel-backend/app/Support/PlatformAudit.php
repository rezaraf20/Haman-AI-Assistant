<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the record of what platform staff did inside a customer account.
 *
 * Every call site is a deliberate one: reading a conversation, revealing a
 * masked contact, changing a customer's widget settings, rotating their
 * webhook secret. The point is not bureaucracy — it is that support has
 * real access to real customers' phone numbers, and access nobody can
 * review afterwards is indistinguishable from no controls at all.
 *
 * Never throws. An audit write failing must not block support from doing
 * their job, but it is logged so a silently broken audit trail does not
 * go unnoticed.
 */
class PlatformAudit
{
    public const REASONS = ['ticket_review', 'error_report', 'customer_request'];

    public static function record(
        string $action,
        ?string $tenantId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $reason = null,
        ?array $changes = null,
    ): void {
        try {
            $user = auth()->user();
            if (!$user) return;

            DB::table('platform_audit_log')->insert([
                'id'            => (string) Str::uuid(),
                'user_id'       => $user->id,
                'user_email'    => $user->email,
                'platform_role' => $user->platform_role,
                'action'        => $action,
                'tenant_id'     => $tenantId,
                'subject_type'  => $subjectType,
                'subject_id'    => $subjectId,
                'reason'        => in_array($reason, self::REASONS, true) ? $reason : null,
                'changes'       => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'ip'            => request()->ip(),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('PlatformAudit write failed: ' . $e->getMessage());
        }
    }

    /**
     * Only the fields that actually changed, each with its before and
     * after. A diff of everything would bury the one line that matters.
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ($oldValue === $newValue) continue;
            $changes[$key] = ['before' => $oldValue, 'after' => $newValue];
        }
        return $changes;
    }

    /** 09121234567 -> 0912***4567, a@b.com -> a***@b.com */
    public static function mask(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') return '';

        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);
            $head = mb_substr($local, 0, 1);
            return "{$head}***@{$domain}";
        }

        $len = mb_strlen($value);
        if ($len <= 4) return str_repeat('*', $len);
        return mb_substr($value, 0, 4) . '***' . mb_substr($value, -4);
    }
}
