<?php
namespace App\Services;

use App\Models\BackupRun;
use App\Support\Settings;
use Illuminate\Support\Facades\{Log, Storage};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Nightly pg_dump, compressed, copied off this server.
 *
 * Deliberately pg_dump's custom format (-Fc) rather than plain SQL: it is
 * already compressed, it can be restored selectively, and pg_restore can
 * read it into a differently-named database without the sed-the-CREATE-
 * DATABASE-line trick that plain dumps need. That last part is what makes
 * hamman:verify-backup able to restore into a scratch database on the same
 * server without touching the real one.
 *
 * The password is passed through PGPASSWORD in the child process's
 * environment, never on the command line, where it would be visible to
 * anyone who can run ps.
 */
class BackupService
{
    /** Where dumps land before upload, and where they stay if there is no remote. */
    public const LOCAL_DIR = 'backups';

    public function __construct(private ?string $disk = null) {}

    /**
     * @return BackupRun the recorded attempt, successful or not
     */
    public function run(string $kind = 'daily'): BackupRun
    {
        $startedAt = now();
        $run = BackupRun::create([
            'kind'       => $kind,
            'status'     => 'running',
            'started_at' => $startedAt,
        ]);

        try {
            $filename = sprintf('hamman-%s-%s.dump', $kind, $startedAt->format('Y-m-d-His'));
            $localPath = $this->localPath($filename);

            @mkdir(dirname($localPath), 0775, true);

            $this->dump($localPath);

            $bytes = filesize($localPath) ?: 0;
            if ($bytes <= 0) {
                throw new \RuntimeException('pg_dump produced an empty file');
            }

            $destination = (string) Settings::get('backup.destination');
            $remotePath = null;

            if ($destination === 's3') {
                $remotePath = $this->upload($localPath, $filename);
            }

            $run->update([
                'status'      => 'success',
                'filename'    => $filename,
                'bytes'       => $bytes,
                'destination' => $destination,
                'remote_path' => $remotePath,
                'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
                'finished_at' => now(),
            ]);

            $this->prune();

            return $run->fresh();
        } catch (\Throwable $e) {
            Log::error('Backup failed: ' . $e->getMessage());

            $run->update([
                'status'      => 'failed',
                'error'       => Str::limit($e->getMessage(), 2000),
                'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
                'finished_at' => now(),
            ]);

            return $run->fresh();
        }
    }

    /**
     * pg_dump in custom format. --no-owner/--no-privileges so the dump can be
     * restored by whatever role does the restoring, which is what a scratch
     * verification database needs.
     */
    public function dump(string $target): void
    {
        $config = config('database.connections.pgsql');

        $process = new Process([
            'pg_dump',
            '--format=custom',
            '--compress=9',
            '--no-owner',
            '--no-privileges',
            '--host=' . $config['host'],
            '--port=' . $config['port'],
            '--username=' . $config['username'],
            '--dbname=' . $config['database'],
            '--file=' . $target,
        ]);

        // Never on the command line: ps would show it to any local process.
        $process->setEnv(['PGPASSWORD' => (string) $config['password']]);
        $process->setTimeout(1800);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('pg_dump failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /** @return string the remote object key */
    public function upload(string $localPath, string $filename): string
    {
        $prefix = trim((string) Settings::get('backup.s3.prefix'), '/');
        $key = ($prefix ? $prefix . '/' : '') . $filename;

        $stream = fopen($localPath, 'rb');
        try {
            $ok = $this->remoteDisk()->writeStream($key, $stream);
            if ($ok === false) {
                throw new \RuntimeException('Upload returned false');
            }
        } finally {
            if (is_resource($stream)) fclose($stream);
        }

        return $key;
    }

    /**
     * Retention: keep the last N daily backups, plus one per week for N weeks.
     *
     * The weekly copies are chosen from the dailies rather than taken
     * separately — a second dump would double the load for a copy of the same
     * data. The Monday backup of each of the last N weeks is kept; everything
     * else older than the daily window goes.
     */
    public function prune(): array
    {
        $keepDaily  = max(1, (int) Settings::get('backup.keep_daily'));
        $keepWeekly = max(0, (int) Settings::get('backup.keep_weekly'));

        $dailyCutoff  = now()->subDays($keepDaily)->startOfDay();
        $weeklyCutoff = now()->subWeeks($keepWeekly)->startOfDay();

        $runs = BackupRun::succeeded()->orderByDesc('started_at')->get();
        $keep = [];
        $weeksSeen = [];

        foreach ($runs as $run) {
            $startedAt = $run->started_at;

            if ($startedAt->greaterThanOrEqualTo($dailyCutoff)) {
                $keep[$run->id] = true;
                continue;
            }

            // One per ISO week, newest first, for as many weeks as configured.
            $week = $startedAt->format('o-W');
            if ($keepWeekly > 0
                && $startedAt->greaterThanOrEqualTo($weeklyCutoff)
                && !isset($weeksSeen[$week])
                && count($weeksSeen) < $keepWeekly) {
                $weeksSeen[$week] = true;
                $keep[$run->id] = true;
            }
        }

        $deleted = [];
        foreach ($runs as $run) {
            if (isset($keep[$run->id]) || !$run->filename) continue;

            $this->deleteFiles($run);
            $deleted[] = $run->filename;
        }

        return $deleted;
    }

    private function deleteFiles(BackupRun $run): void
    {
        $local = $this->localPath($run->filename);
        if (is_file($local)) @unlink($local);

        if ($run->remote_path) {
            try {
                $this->remoteDisk()->delete($run->remote_path);
            } catch (\Throwable $e) {
                // A remote we cannot reach must not stop local pruning, and
                // the row stays so the object is not orphaned unnoticed.
                Log::warning("Could not delete remote backup {$run->remote_path}: " . $e->getMessage());
                return;
            }
        }

        $run->update(['status' => 'pruned']);
    }

    public function localPath(string $filename): string
    {
        return storage_path('app/' . self::LOCAL_DIR . '/' . $filename);
    }

    /**
     * Built here rather than in config/filesystems.php for the same reason
     * the mail settings are: these credentials live in the settings table,
     * and config is cached at build time with no database.
     */
    public function remoteDisk()
    {
        if ($this->disk) return Storage::disk($this->disk);

        $config = [
            'driver'                  => 's3',
            'key'                     => (string) Settings::get('backup.s3.access_key'),
            'secret'                  => (string) Settings::get('backup.s3.secret_key'),
            'region'                  => (string) Settings::get('backup.s3.region'),
            'bucket'                  => (string) Settings::get('backup.s3.bucket'),
            'endpoint'                => (string) Settings::get('backup.s3.endpoint'),
            // Most non-AWS S3 services (Arvan, Liara, MinIO) need this.
            'use_path_style_endpoint' => true,
            'throw'                   => true,
        ];

        foreach (['key', 'secret', 'bucket', 'endpoint'] as $required) {
            if ($config[$required] === '') {
                throw new \RuntimeException("Backup storage is not configured: missing {$required}");
            }
        }

        return Storage::build($config);
    }

    public function isRemoteConfigured(): bool
    {
        return Settings::get('backup.destination') === 's3' && Settings::isConfigured('backup');
    }
}
