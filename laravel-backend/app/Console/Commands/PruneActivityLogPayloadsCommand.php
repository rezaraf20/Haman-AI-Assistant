<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Retention for platform_activity_log: 18 months.
 *
 * Deliberately NOT a delete. After 18 months the rows stay — who did what,
 * to whom, when and from where is the part that answers "was this account
 * ever misused", and that question can be asked years later. What goes is
 * the before/after payload, which is the only part that holds copies of a
 * customer's own data and the only part big enough to matter on disk.
 *
 * So this command issues UPDATE, never DELETE. Nothing in this application
 * deletes from the table at all — see ActivityLog, the admin page, which
 * offers no delete action, and PlatformActivity, which has no delete method.
 */
class PruneActivityLogPayloadsCommand extends Command
{
    protected $signature = 'hamman:prune-activity-log
                            {--months= : Blank payloads older than this many months (default: the settings page value)}
                            {--chunk=5000 : Rows per statement}
                            {--dry-run : Report what would be blanked and change nothing}';

    protected $description = 'Blank before/after payloads on activity-log rows older than the retention window, keeping the rows';

    public function handle(): int
    {
        // The window is a setting so it can be changed without a deploy;
        // the flag still wins when one is passed, for a one-off run.
        $months = max(1, (int) ($this->option('months') ?: Settings::get('system.retention_activity_log_months')));
        $chunk  = max(100, (int) $this->option('chunk'));
        $cutoff = now()->subMonths($months);
        $dryRun = (bool) $this->option('dry-run');

        $pending = DB::table('platform_activity_log')
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNotNull('before')->orWhereNotNull('after'))
            ->count();

        $this->info("Cutoff: {$cutoff->toDateTimeString()} ({$months} months)");
        $this->info("Rows with payloads older than the cutoff: {$pending}");

        if ($pending === 0) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing was changed.');
            return self::SUCCESS;
        }

        // Chunked so a first run against a long-neglected table does not
        // hold one enormous transaction open.
        $blanked = 0;
        do {
            $affected = DB::update(<<<'SQL'
                UPDATE platform_activity_log
                SET "before" = NULL, "after" = NULL
                WHERE id IN (
                    SELECT id FROM platform_activity_log
                    WHERE created_at < ?
                      AND ("before" IS NOT NULL OR "after" IS NOT NULL)
                    LIMIT ?
                )
            SQL, [$cutoff, $chunk]);

            $blanked += $affected;
            if ($affected > 0) $this->line("  blanked {$blanked}/{$pending}");
        } while ($affected > 0);

        $this->info("Done. Payloads blanked on {$blanked} rows; no row was deleted.");

        return self::SUCCESS;
    }
}
