<?php
namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Support\Facades\{Cache, DB};
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Tells platform admins when failed_jobs is growing, instead of the
 * silence it got until now.
 *
 * Found 2026-10-07: 153 rows had piled up there — most from one-off
 * incidents (a document-id race during a single sync, orphaned jobs from
 * since-deleted test tenants), not an ongoing problem, but nobody noticed
 * either way because nothing ever looked at this table. A growing count
 * is the signal worth a human looking at, whatever the eventual cause
 * turns out to be each time.
 */
class CheckFailedJobsCommand extends Command
{
    private const CACHE_KEY = 'failed_jobs.last_seen_count';

    protected $signature = 'haman:check-failed-jobs';
    protected $description = 'Warn platform admins when failed_jobs has grown since the last check';

    public function handle(): int
    {
        $currentCount = (int) DB::table('failed_jobs')->count();
        $lastSeen = (int) Cache::get(self::CACHE_KEY, $currentCount);

        if ($currentCount <= $lastSeen) {
            $this->info("failed_jobs: {$currentCount} rows, no growth since last check ({$lastSeen}).");
            Cache::forever(self::CACHE_KEY, $currentCount);
            return self::SUCCESS;
        }

        $grew = $currentCount - $lastSeen;
        $this->warn("failed_jobs grew by {$grew} (from {$lastSeen} to {$currentCount}).");

        $recent = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($grew)
            ->get(['queue', 'failed_at', 'payload']);

        $byClass = [];
        foreach ($recent as $row) {
            if (preg_match('/"displayName":"([^"]*)"/', $row->payload, $m)) {
                $byClass[$m[1]] = ($byClass[$m[1]] ?? 0) + 1;
            }
        }
        $summary = $byClass
            ? implode(', ', array_map(fn ($cls, $n) => "{$cls} x{$n}", array_keys($byClass), $byClass))
            : "{$grew} job(s)";

        $admins = User::where('is_platform_admin', true)->get();
        foreach ($admins as $admin) {
            Notification::make()
                ->title(__('settings.failed_jobs_alert_title', ['count' => $grew]))
                ->body(__('settings.failed_jobs_alert_body', ['summary' => $summary, 'total' => $currentCount]))
                ->danger()
                ->sendToDatabase($admin);
        }

        Cache::forever(self::CACHE_KEY, $currentCount);
        $this->line("Notified {$admins->count()} admin(s): {$summary}");

        return self::SUCCESS;
    }
}
