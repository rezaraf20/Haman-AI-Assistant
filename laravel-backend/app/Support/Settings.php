<?php
namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Log;

/**
 * Reads and writes platform settings.
 *
 * Three rules hold this together:
 *
 *  1. Only values that DIFFER from the declared default are stored. So
 *     resetting is a delete, and raising a default in code reaches every
 *     installation that never overrode it.
 *
 *  2. Secrets are encrypted at rest with the same AES-256-GCM envelope the
 *     LLM keys use (LlmKeyCrypto), and never leave the server in the clear.
 *     The admin page asks isConfigured(); there is no accessor that hands a
 *     secret to a Blade view.
 *
 *  3. Unknown keys throw. A typo returning null silently is how a rate limit
 *     becomes unlimited.
 *
 * Cached per request, because the limits are read on hot paths (every chat
 * message asks for at least two of them).
 */
class Settings
{
    private static ?array $cache = null;

    public static function get(string $key): mixed
    {
        $definition = SettingsRegistry::definition($key);
        $raw = self::rawValue($key, $definition);

        if ($raw === null) return $definition['default'];

        return self::castOut($raw, $definition);
    }

    /** The value with secrets replaced by null — safe to hand to a view. */
    public static function redactedFor(string $key): mixed
    {
        return SettingsRegistry::isSecret($key) ? null : self::get($key);
    }

    /** True when a secret (or any value) has actually been set. */
    public static function isSet(string $key): bool
    {
        $definition = SettingsRegistry::definition($key);
        $raw = self::rawValue($key, $definition);

        return $raw !== null && $raw !== '';
    }

    /**
     * Whether a whole group is usable. Used for the configured/not-configured
     * badge, and — for email — for whether the channel runs at all.
     */
    public static function isConfigured(string $group): bool
    {
        $required = SettingsRegistry::requiredFor($group);
        if (!$required) return false;

        foreach ($required as $key) {
            if (!self::isSet($key)) return false;
        }
        return true;
    }

    public static function set(string $key, mixed $value): void
    {
        $definition = SettingsRegistry::definition($key);
        $settings = PlatformSetting::current();

        // An empty string means "cleared", not "set to empty".
        if ($value === '' ) $value = null;

        if (isset($definition['column'])) {
            $settings->{$definition['column']} = $value === null
                ? null
                : self::castIn($value, $definition);
            $settings->save();
            self::forget();
            return;
        }

        $values = $settings->values ?? [];

        // Storing a value equal to the default would freeze it against a
        // future change in code, so it is stored as absent instead.
        if ($value === null || self::equalsDefault($value, $definition)) {
            unset($values[$key]);
        } else {
            $values[$key] = self::castIn($value, $definition);
        }

        $settings->values = $values;
        $settings->save();
        self::forget();
    }

    /** Back to the declared default. */
    public static function reset(string $key): void
    {
        SettingsRegistry::definition($key);   // validates the key exists

        $settings = PlatformSetting::current();
        $definition = SettingsRegistry::definition($key);

        if (isset($definition['column'])) {
            $settings->{$definition['column']} = $definition['default'];
        } else {
            $values = $settings->values ?? [];
            unset($values[$key]);
            $settings->values = $values;
        }

        $settings->save();
        self::forget();
    }

    /** True when this key currently differs from its declared default. */
    public static function isOverridden(string $key): bool
    {
        $definition = SettingsRegistry::definition($key);

        if (isset($definition['column'])) {
            return self::get($key) !== $definition['default'];
        }

        $values = PlatformSetting::current()->values ?? [];
        return array_key_exists($key, $values);
    }

    public static function forget(): void
    {
        self::$cache = null;
        self::invalidateSharedCache();
    }

    /**
     * The Python service caches this same row for 60s (see
     * platform_settings_service.py). Dropping its key here means a changed
     * limit takes effect there immediately rather than up to a minute later
     * — which matters when the value being changed is a rate limit someone
     * is raising in response to a problem happening right now.
     */
    private static function invalidateSharedCache(): void
    {
        try {
            \Illuminate\Support\Facades\Redis::del('haman:platform_settings:values');
        } catch (\Throwable $e) {
            Log::debug('Could not invalidate shared settings cache: ' . $e->getMessage());
        }
    }

    // ── internals ───────────────────────────────────────────────────────

    private static function rawValue(string $key, array $definition): mixed
    {
        $settings = self::row();
        if (!$settings) return null;

        $stored = isset($definition['column'])
            ? ($settings['columns'][$definition['column']] ?? null)
            : ($settings['values'][$key] ?? null);

        if ($stored === null || $stored === '') return null;

        if ($definition['type'] === 'secret') {
            // Rows written before encryption existed decrypt to null; fall
            // back to the raw value so they keep working until next saved.
            return LlmKeyCrypto::decrypt((string) $stored) ?? $stored;
        }

        return $stored;
    }

    private static function row(): ?array
    {
        if (self::$cache !== null) return self::$cache;

        try {
            $model = PlatformSetting::current();
            return self::$cache = [
                'columns' => $model->getAttributes(),
                'values'  => $model->values ?? [],
            ];
        } catch (\Throwable $e) {
            // Console commands run before the table exists (migrate itself,
            // for one). Falling back to declared defaults is correct there.
            Log::debug('Settings unavailable, using defaults: ' . $e->getMessage());
            return null;
        }
    }

    private static function castOut(mixed $value, array $definition): mixed
    {
        return match ($definition['type']) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }

    private static function castIn(mixed $value, array $definition): mixed
    {
        return match ($definition['type']) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => (bool) $value,
            'secret' => LlmKeyCrypto::encrypt((string) $value),
            default  => (string) $value,
        };
    }

    private static function equalsDefault(mixed $value, array $definition): bool
    {
        // A secret never compares equal to its default: it is encrypted with
        // a fresh nonce each time, and a default of null is not a secret.
        if ($definition['type'] === 'secret') return false;

        return self::castOut($value, $definition) === $definition['default'];
    }
}
