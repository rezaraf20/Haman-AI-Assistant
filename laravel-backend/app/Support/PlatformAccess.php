<?php
namespace App\Support;

use App\Models\User;

/**
 * Every platform-staff authorisation rule, in one place.
 *
 * Deliberately centralised: the vulnerability this system already had
 * (role==='owner' reaching the admin panel) came from an authorisation
 * decision written inline in one spot, where nobody could see the whole
 * picture. Filament resources and pages delegate here instead of each
 * inventing their own check, so the answer to "what can support see?" is
 * one readable list rather than thirty scattered booleans.
 *
 * Two rules that must never be relaxed:
 *
 *  1. Platform role is NOT tenant role. users.role is 'owner' for every
 *     customer's first user; it grants nothing here. Only platform_role
 *     does, and no tenant-facing code path can write it.
 *
 *  2. Hiding navigation is not authorisation. Every capability below is
 *     enforced by canViewAny()/canAccess() on the resource or page, which
 *     Filament checks on the direct route too — navigation visibility is
 *     cosmetic on top of that.
 */
class PlatformAccess
{
    /** Things only a full platform admin may reach. */
    public const ADMIN_ONLY = [
        // Platform finances and margin.
        'platform_finances',
        // LLM provider credentials, payment gateway settings, SMS config.
        'platform_settings',
        // Raw API keys and webhook secret values.
        'api_key_values',
        // Wallet ledger: viewing it in aggregate is platform revenue, and
        // editing it is handing out money.
        'wallet_ledger',
        // Pricing: plans, chatbot type prices, token packages.
        'pricing',
        // Other platform users, and what they did.
        'platform_users',
        // Destructive tenant operations: delete, change plan.
        'tenant_lifecycle',
    ];

    /** Things platform staff of any role may reach. */
    public const STAFF = [
        'tenants_read',        // tenant list + status
        'chatbots_read',       // chatbots, widget settings, sync state
        'sync_operate',        // run a sync, clear a cache
        'tickets',             // read and reply
        'usage_read',          // token usage + wallet BALANCE (not the ledger)
        'tenant_reports',      // trends, demand gap, leads, suggestions
        'widget_settings_edit',// the single most common support request
        'webhook_secret_rotate', // rotate without ever seeing the old value
        'conversations',       // gated further: reason required, values masked
    ];

    public static function isStaff(?User $user): bool
    {
        return $user !== null
            && in_array($user->platform_role, ['admin', 'support'], true)
            && (bool) $user->platform_is_active;
    }

    public static function isAdmin(?User $user): bool
    {
        return $user !== null
            && $user->platform_role === 'admin'
            && (bool) $user->platform_is_active;
    }

    public static function isSupport(?User $user): bool
    {
        return $user !== null
            && $user->platform_role === 'support'
            && (bool) $user->platform_is_active;
    }

    /**
     * The single question every resource and page asks.
     *
     * Unknown capabilities are admin-only by default: a new feature that
     * forgets to declare itself must fail closed, not quietly become
     * visible to support.
     */
    public static function can(?User $user, string $capability): bool
    {
        if (!self::isStaff($user)) return false;
        if (self::isAdmin($user)) return true;

        return in_array($capability, self::STAFF, true);
    }

    /** Convenience for the current request. */
    public static function allows(string $capability): bool
    {
        $user = auth()->user();
        return $user instanceof User && self::can($user, $capability);
    }

    /**
     * Hard stop, for use INSIDE an action's own closure.
     *
     * Filament's ->visible() on a row action controls rendering; a custom
     * Action::make('delete') is not covered by the resource's canDelete()
     * either. Since the closures behind these actions drop tenant schemas
     * and delete chatbots, they re-check here, where the work actually
     * happens, rather than trusting that the button was never rendered.
     */
    public static function authorize(string $capability): void
    {
        abort_unless(self::allows($capability), 403);
    }
}
