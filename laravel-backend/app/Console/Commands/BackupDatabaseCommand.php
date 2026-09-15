<?php
namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * The nightly backup.
 *
 * Kind is derived from the day rather than scheduled twice: the Monday run is
 * tagged weekly so retention can keep one per week without taking a second
 * dump of the same data.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'haman:backup-database
                            {--kind= : daily | weekly | manual (default: weekly on Mondays, else daily)}
                            {--prune-only : Apply retention without taking a new backup}';

    protected $description = 'Dump the database, compress it, copy it off this server, and apply retention';

    public function handle(BackupService $backups): int
    {
        if ($this->option('prune-only')) {
            $deleted = $backups->prune();
            $this->info(count($deleted) . ' backup(s) pruned.');
            foreach ($deleted as $name) $this->line('  - ' . $name);
            return self::SUCCESS;
        }

        $kind = $this->option('kind') ?: (now()->isMonday() ? 'weekly' : 'daily');

        if (!$backups->isRemoteConfigured()) {
            // Loud, because a backup that never leaves the machine it is
            // protecting is not a backup — but it still runs, since a local
            // copy beats nothing while the remote is being set up.
            $this->warn('No off-server destination configured — this copy stays on the same machine as the database.');
        }

        $this->info("Running {$kind} backup...");
        $run = $backups->run($kind);

        if ($run->status !== 'success') {
            $this->error('Backup FAILED: ' . $run->error);
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup succeeded: %s (%s) in %.1fs%s',
            $run->filename,
            $run->humanSize(),
            $run->duration_ms / 1000,
            $run->remote_path ? ' -> ' . $run->remote_path : ' (local only)',
        ));

        return self::SUCCESS;
    }
}
