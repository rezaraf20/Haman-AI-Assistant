<?php
namespace App\Services;

use App\Support\Settings;
use Symfony\Component\Process\Process;

/**
 * A pg_dump of exactly one tenant schema, taken right before that schema is
 * ever DROPped — by DropPendingDeletionTenantsCommand at the end of the
 * grace period, or by TenantService::permanentlyDeleteNow() for the
 * immediate path. Cheap (one schema, not the whole database) and the only
 * real insurance against a mistaken or malicious deletion once the schema
 * itself is gone — see TenantResource's deletion actions.
 *
 * Deliberately its own small class rather than reusing BackupService: that
 * one dumps the whole database, records a BackupRun row, and uploads
 * off-server — none of which fits "one schema, right before it disappears
 * forever". This writes plain files and prunes by file age; nothing here
 * is worth a database table of its own.
 */
class TenantSchemaBackupService
{
    public const DIR = 'tenant-deletion-backups';

    public function dump(string $schemaName): string
    {
        $filename = sprintf('%s-%s.dump', $schemaName, now()->format('Y-m-d-His'));
        $path = $this->localPath($filename);
        @mkdir(dirname($path), 0775, true);

        $config = config('database.connections.pgsql');

        $process = new Process([
            'pg_dump',
            '--format=custom',
            '--compress=9',
            '--no-owner',
            '--no-privileges',
            '--schema=' . $schemaName,
            '--host=' . $config['host'],
            '--port=' . $config['port'],
            '--username=' . $config['username'],
            '--dbname=' . $config['database'],
            '--file=' . $path,
        ]);
        // Never on the command line: ps would show it to any local process.
        $process->setEnv(['PGPASSWORD' => (string) $config['password']]);
        $process->setTimeout(1800);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("pg_dump of schema {$schemaName} failed: " . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        if (!is_file($path) || filesize($path) === 0) {
            throw new \RuntimeException("pg_dump of schema {$schemaName} produced an empty file");
        }

        return $path;
    }

    public function localPath(string $filename): string
    {
        return storage_path('app/' . self::DIR . '/' . $filename);
    }

    /** @return string[] filenames removed */
    public function prune(): array
    {
        $keepDays = max(1, (int) Settings::get('limits.tenant_schema_backup_retention_days'));
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $dir = storage_path('app/' . self::DIR);

        $removed = [];
        foreach (glob($dir . '/*.dump') ?: [] as $file) {
            if ((filemtime($file) ?: 0) < $cutoff) {
                @unlink($file);
                $removed[] = basename($file);
            }
        }

        return $removed;
    }
}
