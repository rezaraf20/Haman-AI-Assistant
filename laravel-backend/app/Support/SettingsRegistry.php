<?php
namespace App\Support;

/**
 * Every platform setting, declared in one place.
 *
 * A setting that is not declared here does not exist: Settings::get() throws
 * on an unknown key rather than returning null, because a silent null is how
 * a limit becomes "unlimited" without anyone noticing.
 *
 * Each entry carries its own default. That is deliberate and does more work
 * than it looks: only values that DIFFER from the default are stored, so
 * "reset to default" is a delete rather than a second copy of the number,
 * and raising a default in code actually reaches every installation that
 * never overrode it.
 *
 * `column` maps a key onto one of the pre-existing platform_settings columns
 * (Zarinpal, Melipayamak, SMS cost). Those are read directly by other
 * services and by Python, so they stay columns; the registry just gives them
 * the same uniform interface as everything else.
 *
 * `secret` means the value is encrypted at rest and NEVER sent to the
 * browser. The page shows "configured" plus a change button, never the value
 * — see Settings::redactedFor().
 */
class SettingsRegistry
{
    public const TABS = ['payments', 'email', 'sms', 'pricing', 'limits', 'system'];

    /**
     * type: string | secret | int | float | bool | select
     * group: which panel inside the tab
     */
    public static function all(): array
    {
        return [
            // ── Tab 1: payments ──────────────────────────────────────────
            'payments.zarinpal.merchant_id' => [
                'tab' => 'payments', 'group' => 'zarinpal', 'type' => 'secret',
                'column' => 'zarinpal_merchant_id', 'default' => null,
            ],
            'payments.zarinpal.sandbox' => [
                'tab' => 'payments', 'group' => 'zarinpal', 'type' => 'bool',
                'column' => 'zarinpal_sandbox', 'default' => true,
            ],

            'payments.stripe.publishable_key' => [
                'tab' => 'payments', 'group' => 'stripe', 'type' => 'string', 'default' => null,
            ],
            'payments.stripe.secret_key' => [
                'tab' => 'payments', 'group' => 'stripe', 'type' => 'secret', 'default' => null,
            ],
            'payments.stripe.webhook_secret' => [
                'tab' => 'payments', 'group' => 'stripe', 'type' => 'secret', 'default' => null,
            ],

            'payments.paddle.vendor_id' => [
                'tab' => 'payments', 'group' => 'paddle', 'type' => 'string', 'default' => null,
            ],
            'payments.paddle.api_key' => [
                'tab' => 'payments', 'group' => 'paddle', 'type' => 'secret', 'default' => null,
            ],
            'payments.paddle.webhook_secret' => [
                'tab' => 'payments', 'group' => 'paddle', 'type' => 'secret', 'default' => null,
            ],
            'payments.paddle.sandbox' => [
                'tab' => 'payments', 'group' => 'paddle', 'type' => 'bool', 'default' => true,
            ],

            // Which gateway takes which currency. Toman stays with Zarinpal
            // because it is the only one of the three that handles it.
            'payments.gateway.IRT' => [
                'tab' => 'payments', 'group' => 'routing', 'type' => 'select',
                'options' => ['zarinpal', 'stripe', 'paddle'], 'default' => 'zarinpal',
            ],
            'payments.gateway.USD' => [
                'tab' => 'payments', 'group' => 'routing', 'type' => 'select',
                'options' => ['zarinpal', 'stripe', 'paddle'], 'default' => 'stripe',
            ],
            'payments.gateway.EUR' => [
                'tab' => 'payments', 'group' => 'routing', 'type' => 'select',
                'options' => ['zarinpal', 'stripe', 'paddle'], 'default' => 'stripe',
            ],

            'payments.fx.mode' => [
                'tab' => 'payments', 'group' => 'fx', 'type' => 'select',
                'options' => ['manual', 'source'], 'default' => 'manual',
            ],
            'payments.fx.usd_to_toman' => [
                'tab' => 'payments', 'group' => 'fx', 'type' => 'float', 'default' => 0.0,
            ],
            'payments.fx.eur_to_toman' => [
                'tab' => 'payments', 'group' => 'fx', 'type' => 'float', 'default' => 0.0,
            ],
            'payments.fx.source_url' => [
                'tab' => 'payments', 'group' => 'fx', 'type' => 'string', 'default' => null,
            ],

            // ── Tab 2: email ─────────────────────────────────────────────
            'mail.host' => ['tab' => 'email', 'group' => 'smtp', 'type' => 'string', 'default' => null],
            'mail.port' => ['tab' => 'email', 'group' => 'smtp', 'type' => 'int', 'default' => 587],
            'mail.encryption' => [
                'tab' => 'email', 'group' => 'smtp', 'type' => 'select',
                'options' => ['tls', 'ssl', 'none'], 'default' => 'tls',
            ],
            'mail.username' => ['tab' => 'email', 'group' => 'smtp', 'type' => 'string', 'default' => null],
            'mail.password' => ['tab' => 'email', 'group' => 'smtp', 'type' => 'secret', 'default' => null],
            'mail.from_address' => ['tab' => 'email', 'group' => 'sender', 'type' => 'string', 'default' => null],
            'mail.from_name' => ['tab' => 'email', 'group' => 'sender', 'type' => 'string', 'default' => 'Haman AI'],

            // ── Tab 3: sms ───────────────────────────────────────────────
            'sms.provider' => [
                'tab' => 'sms', 'group' => 'provider', 'type' => 'select',
                'options' => ['melipayamak'], 'default' => 'melipayamak',
            ],
            'sms.melipayamak.username' => [
                'tab' => 'sms', 'group' => 'melipayamak', 'type' => 'string',
                'column' => 'melipayamak_username', 'default' => null,
            ],
            'sms.melipayamak.password' => [
                'tab' => 'sms', 'group' => 'melipayamak', 'type' => 'secret',
                'column' => 'melipayamak_password', 'default' => null,
            ],
            'sms.melipayamak.sender' => [
                'tab' => 'sms', 'group' => 'melipayamak', 'type' => 'string',
                'column' => 'melipayamak_sender', 'default' => null,
            ],
            'sms.melipayamak.use_pattern' => [
                'tab' => 'sms', 'group' => 'melipayamak', 'type' => 'bool',
                'column' => 'melipayamak_use_pattern', 'default' => false,
            ],
            'sms.melipayamak.pattern_id' => [
                'tab' => 'sms', 'group' => 'melipayamak', 'type' => 'string',
                'column' => 'melipayamak_pattern_id', 'default' => null,
            ],
            'sms.cost_toman' => [
                'tab' => 'sms', 'group' => 'cost', 'type' => 'int',
                'column' => 'sms_cost_toman', 'default' => 0,
            ],
            'sms.daily_cap_per_tenant' => [
                'tab' => 'sms', 'group' => 'cost', 'type' => 'int', 'default' => 200,
            ],
            'sms.daily_cap_platform' => [
                'tab' => 'sms', 'group' => 'cost', 'type' => 'int', 'default' => 5000,
            ],

            // ── Tab 4: pricing ───────────────────────────────────────────
            'pricing.default_currency' => [
                'tab' => 'pricing', 'group' => 'currency', 'type' => 'select',
                'options' => ['IRT', 'USD', 'EUR'], 'default' => 'IRT',
            ],
            'pricing.embedding_cost_per_1m_toman' => [
                'tab' => 'pricing', 'group' => 'cost', 'type' => 'float', 'default' => 0.0,
            ],
            'pricing.default_margin_multiplier' => [
                'tab' => 'pricing', 'group' => 'cost', 'type' => 'float', 'default' => 1.5,
            ],
            // Which published plan the landing page marks "Popular". A slug,
            // not a select with fixed options here — the choices are plans,
            // which are dynamic rows, not an enum this registry can list
            // ahead of time. Settings.php builds the actual dropdown itself
            // and just writes to this same field name. Empty means none.
            'pricing.popular_plan_slug' => [
                'tab' => 'pricing', 'group' => 'currency', 'type' => 'string', 'default' => '',
            ],

            // ── Tab 5: limits ────────────────────────────────────────────
            // Defaults below are the values these were hardcoded to before
            // this page existed. Changing one here changes behaviour; the
            // reset button puts it back to exactly this number.
            'limits.chat_session_per_minute' => [
                'tab' => 'limits', 'group' => 'chat', 'type' => 'int', 'default' => 20,
            ],
            'limits.chat_message_per_minute' => [
                'tab' => 'limits', 'group' => 'chat', 'type' => 'int', 'default' => 30,
            ],
            'limits.payment_links_per_conversation' => [
                'tab' => 'limits', 'group' => 'tools', 'type' => 'int', 'default' => 3,
            ],
            'limits.payment_links_per_ip_per_day' => [
                'tab' => 'limits', 'group' => 'tools', 'type' => 'int', 'default' => 5,
            ],
            'limits.max_tool_calls_per_message' => [
                'tab' => 'limits', 'group' => 'tools', 'type' => 'int', 'default' => 3,
            ],
            'limits.otp_codes_per_contact_per_hour' => [
                'tab' => 'limits', 'group' => 'otp', 'type' => 'int', 'default' => 3,
            ],
            'limits.otp_codes_per_chatbot_per_day' => [
                'tab' => 'limits', 'group' => 'otp', 'type' => 'int', 'default' => 100,
            ],
            'limits.otp_requests_per_ip_per_day' => [
                'tab' => 'limits', 'group' => 'otp', 'type' => 'int', 'default' => 20,
            ],
            'limits.otp_verify_attempts' => [
                'tab' => 'limits', 'group' => 'otp', 'type' => 'int', 'default' => 3,
            ],
            'limits.otp_ttl_minutes' => [
                'tab' => 'limits', 'group' => 'otp', 'type' => 'int', 'default' => 5,
            ],
            // Added by the abuse audit: every one of these guards an
            // endpoint that either costs money or was brute-forceable.
            'limits.login_attempts_per_minute' => [
                'tab' => 'limits', 'group' => 'auth', 'type' => 'int', 'default' => 5,
            ],
            'limits.login_lockout_minutes' => [
                'tab' => 'limits', 'group' => 'auth', 'type' => 'int', 'default' => 15,
            ],
            'limits.login_attempts_before_lockout' => [
                'tab' => 'limits', 'group' => 'auth', 'type' => 'int', 'default' => 10,
            ],
            'limits.register_per_ip_per_day' => [
                'tab' => 'limits', 'group' => 'auth', 'type' => 'int', 'default' => 3,
            ],
            'limits.portal_otp_per_ip_per_day' => [
                'tab' => 'limits', 'group' => 'otp', 'type' => 'int', 'default' => 10,
            ],
            // Manual sync from the customer portal re-embeds the whole
            // catalogue, so the merchant gets a bounded number per day.
            // Support is not capped here: the plugin enforces its own
            // one-per-hour guard per site, and a support-initiated sync is
            // a deliberate act on a ticket, not a button anyone can lean on.
            'limits.manual_sync_per_tenant_per_day' => [
                'tab' => 'limits', 'group' => 'sync', 'type' => 'int', 'default' => 3,
            ],
            'limits.sync_requests_per_minute' => [
                'tab' => 'limits', 'group' => 'sync', 'type' => 'int', 'default' => 30,
            ],
            'limits.sync_items_per_request' => [
                'tab' => 'limits', 'group' => 'sync', 'type' => 'int', 'default' => 50,
            ],
            'limits.public_read_per_minute' => [
                'tab' => 'limits', 'group' => 'chat', 'type' => 'int', 'default' => 60,
            ],

            'limits.pdf_max_mb' => [
                'tab' => 'limits', 'group' => 'documents', 'type' => 'int', 'default' => 10,
            ],
            'limits.pdf_max_pages' => [
                'tab' => 'limits', 'group' => 'documents', 'type' => 'int', 'default' => 100,
            ],
            'limits.retrieval_threshold' => [
                'tab' => 'limits', 'group' => 'retrieval', 'type' => 'float', 'default' => 0.60,
            ],
            'limits.rerank_threshold' => [
                'tab' => 'limits', 'group' => 'retrieval', 'type' => 'float', 'default' => 0.50,
            ],

            // ── Tab 6: system ────────────────────────────────────────────
            'system.retention_event_payload_days' => [
                'tab' => 'system', 'group' => 'retention', 'type' => 'int', 'default' => 90,
            ],
            'system.retention_activity_log_months' => [
                'tab' => 'system', 'group' => 'retention', 'type' => 'int', 'default' => 18,
            ],
            // Off-server backup destination. S3-compatible on purpose:
            // Arvan, Liara and Backblaze all speak it, so the platform is
            // not tied to one provider — and the whole point is that the
            // copy does not live on the machine that just died.
            'backup.destination' => [
                'tab' => 'system', 'group' => 'backup', 'type' => 'select',
                'options' => ['local_only', 's3'], 'default' => 'local_only',
            ],
            'backup.s3.endpoint' => [
                'tab' => 'system', 'group' => 'backup', 'type' => 'string', 'default' => null,
            ],
            'backup.s3.region' => [
                'tab' => 'system', 'group' => 'backup', 'type' => 'string', 'default' => 'us-east-1',
            ],
            'backup.s3.bucket' => [
                'tab' => 'system', 'group' => 'backup', 'type' => 'string', 'default' => null,
            ],
            'backup.s3.access_key' => [
                'tab' => 'system', 'group' => 'backup', 'type' => 'secret', 'default' => null,
            ],
            'backup.s3.secret_key' => [
                'tab' => 'system', 'group' => 'backup', 'type' => 'secret', 'default' => null,
            ],
            'backup.s3.prefix' => [
                'tab' => 'system', 'group' => 'backup', 'type' => 'string', 'default' => 'haman-backups',
            ],
            'backup.keep_daily' => [
                'tab' => 'system', 'group' => 'backup', 'type' => 'int', 'default' => 7,
            ],
            'backup.keep_weekly' => [
                'tab' => 'system', 'group' => 'backup', 'type' => 'int', 'default' => 4,
            ],

            'system.maintenance_mode' => [
                'tab' => 'system', 'group' => 'maintenance', 'type' => 'bool', 'default' => false,
            ],
            'system.maintenance_message' => [
                'tab' => 'system', 'group' => 'maintenance', 'type' => 'string', 'default' => null,
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function definition(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new \InvalidArgumentException("Unknown setting [{$key}]. Declare it in SettingsRegistry.");
        }
        return $all[$key];
    }

    /** @return string[] */
    public static function keysForTab(string $tab): array
    {
        return array_keys(array_filter(self::all(), fn ($d) => $d['tab'] === $tab));
    }

    public static function isSecret(string $key): bool
    {
        return self::definition($key)['type'] === 'secret';
    }

    public static function default(string $key): mixed
    {
        return self::definition($key)['default'];
    }

    /**
     * The keys whose presence decides whether a group counts as configured.
     * A group with none of these set is shown as "not configured" and, for
     * email, is dropped from the notification chain entirely.
     */
    public static function requiredFor(string $group): array
    {
        return match ($group) {
            'zarinpal'    => ['payments.zarinpal.merchant_id'],
            'stripe'      => ['payments.stripe.secret_key'],
            'paddle'      => ['payments.paddle.api_key', 'payments.paddle.vendor_id'],
            'email'       => ['mail.host', 'mail.from_address'],
            'melipayamak' => ['sms.melipayamak.username', 'sms.melipayamak.password', 'sms.melipayamak.sender'],
            // A backup that only ever lands on the same server is not an
            // off-server backup, so "configured" means the remote is set up.
            'backup'      => ['backup.s3.endpoint', 'backup.s3.bucket', 'backup.s3.access_key', 'backup.s3.secret_key'],
            default       => [],
        };
    }
}
