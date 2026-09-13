<?php
namespace App\Support;

use Illuminate\Support\Facades\{Config, Log, Mail};

/**
 * Pushes the admin-configured SMTP credentials into the live mail config.
 *
 * config/mail.php cannot read the database itself — `config:cache` runs with
 * no database, and artisan has to work before the settings table exists — so
 * env stays the fallback there and this layers the real values on top once
 * the app is running.
 *
 * apply() is called from AppServiceProvider::boot() and again immediately
 * before a test send, so changing a setting takes effect without a restart.
 */
class MailSettings
{
    public static function apply(): void
    {
        try {
            if (!Settings::isConfigured('email')) return;

            $encryption = Settings::get('mail.encryption');

            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.host', Settings::get('mail.host'));
            Config::set('mail.mailers.smtp.port', (int) Settings::get('mail.port'));
            Config::set('mail.mailers.smtp.encryption', $encryption === 'none' ? null : $encryption);
            Config::set('mail.mailers.smtp.username', Settings::get('mail.username'));
            Config::set('mail.mailers.smtp.password', Settings::get('mail.password'));
            Config::set('mail.from.address', Settings::get('mail.from_address'));
            Config::set('mail.from.name', Settings::get('mail.from_name'));

            // Laravel caches the built mailer, so a settings change would
            // otherwise keep sending through the old credentials.
            Mail::purge('smtp');
        } catch (\Throwable $e) {
            Log::warning('MailSettings::apply failed: ' . $e->getMessage());
        }
    }

    /**
     * Whether email may be used at all.
     *
     * NotificationService asks this before dispatching, so an unconfigured
     * platform drops the channel instead of throwing inside a queue worker
     * where nobody sees it.
     */
    public static function isUsable(): bool
    {
        return Settings::isConfigured('email');
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public static function sendTest(string $to): array
    {
        if (!self::isUsable()) {
            return ['ok' => false, 'message' => __('settings.email_not_configured')];
        }

        self::apply();

        try {
            Mail::raw(__('settings.test_email_body'), function ($message) use ($to) {
                $message->to($to)->subject(__('settings.test_email_subject'));
            });

            return ['ok' => true, 'message' => __('settings.test_email_sent', ['address' => $to])];
        } catch (\Throwable $e) {
            // The SMTP error itself is what makes this button worth having,
            // so it is surfaced rather than swallowed into a generic failure.
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
