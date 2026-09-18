<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Support\CertificateExpiry;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Tells the platform admins before a certificate expires, not after.
 *
 * Renewal starts about 30 days out, so anything still under 21 days has
 * already had a week of chances and not taken them. That is the point worth
 * waking someone for: early enough to fix by hand, late enough that it is not
 * just noise about a renewal that is going to happen on its own.
 *
 * Notifications are throttled to one per host per day. Without that this runs
 * daily into the same unresolved problem and buries every other notification
 * under three weeks of duplicates.
 */
class CheckCertificatesCommand extends Command
{
    protected $signature = 'haman:check-certificates {--fresh : ignore the cache}';
    protected $description = 'Warn platform admins about TLS certificates close to expiry';

    public function handle(): int
    {
        $failing = CertificateExpiry::failing();

        if (!$failing) {
            $this->info('All certificates are healthy.');
            foreach (CertificateExpiry::all() as $c) {
                $this->line("  {$c['host']}  {$c['expires_at']}  ({$c['days']} days)");
            }
            return self::SUCCESS;
        }

        $admins = User::where('is_platform_admin', true)->get();

        foreach ($failing as $cert) {
            $host = $cert['host'];

            $summary = $cert['error']
                ? __('settings.cert_unreadable', ['host' => $host, 'error' => $cert['error']])
                : __('settings.cert_expiring', ['host' => $host, 'days' => $cert['days'], 'date' => $cert['expires_at']]);

            $this->warn($summary);

            // One per host per day, whatever the schedule does.
            $throttle = 'certificates.notified.' . $host . '.' . now()->toDateString();
            if (Cache::has($throttle)) {
                $this->line("  (already notified today about {$host})");
                continue;
            }
            Cache::put($throttle, true, now()->endOfDay());

            foreach ($admins as $admin) {
                Notification::make()
                    ->title(__('settings.cert_alert_title', ['host' => $host]))
                    ->body($summary)
                    ->danger()
                    ->sendToDatabase($admin);
            }

            $this->line("  notified {$admins->count()} admin(s) about {$host}");
        }

        return self::SUCCESS;
    }
}
