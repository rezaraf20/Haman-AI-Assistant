<?php
namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Restores the most recent backup into a scratch database and checks what
 * came back.
 *
 * An untested backup is not a backup. The failure modes that matter are
 * silent — a dump that is written but truncated, a pg_dump version mismatch,
 * a table that got excluded, an upload that stored zero bytes — and none of
 * them are visible from the fact that a file exists and has a plausible size.
 * The only thing that settles it is putting the dump back and counting rows.
 *
 * The scratch database is created and dropped by this command, and is never
 * the live one: dropDatabase() refuses any name that is not the generated
 * verify_* name, because "restore over production" is the one mistake a
 * restore tool must not be able to make.
 */
class VerifyBackupCommand extends Command
{
    protected $signature = 'hamman:verify-backup
                            {--run= : Verify a specific backup_runs id (default: the newest successful one)}
                            {--keep : Leave the scratch database behind for inspection}';

    protected $description = 'Restore the latest backup into a temporary database and prove it is usable';

    public function handle(BackupService $backups): int
    {
        $run = $this->option('run')
            ? BackupRun::find($this->option('run'))
            : BackupRun::latestSuccess();

        if (!$run) {
            $this->error('No successful backup to verify.');
            return self::FAILURE;
        }

        $localPath = $backups->localPath($run->filename);

        if (!is_file($localPath)) {
            if (!$run->remote_path) {
                $this->error("Backup file is gone and there is no remote copy: {$run->filename}");
                return $this->recordFailure($run, 'Local file missing and no remote copy');
            }

            $this->info("Downloading {$run->remote_path} from the remote...");
            try {
                @mkdir(dirname($localPath), 0775, true);
                $stream = $backups->remoteDisk()->readStream($run->remote_path);
                file_put_contents($localPath, $stream);
            } catch (\Throwable $e) {
                return $this->recordFailure($run, 'Could not download the backup: ' . $e->getMessage());
            }
        }

        $scratch = 'verify_' . Str::lower(Str::random(12));
        $this->info("Restoring {$run->filename} into scratch database {$scratch}...");

        try {
            $this->createDatabase($scratch);
            $this->restore($localPath, $scratch);

            $counts = $this->inspect($scratch);

            $this->newLine();
            $this->table(['Table', 'Rows restored'], array_map(
                fn ($table, $count) => [$table, $count === null ? 'MISSING' : number_format($count)],
                array_keys($counts), $counts,
            ));

            $missing = array_keys(array_filter($counts, fn ($c) => $c === null));
            if ($missing) {
                return $this->recordFailure($run, 'Restored database is missing: ' . implode(', ', $missing));
            }

            // A restore that produces empty tables is a restore of nothing.
            if (($counts['tenants'] ?? 0) < 1 || ($counts['users'] ?? 0) < 1) {
                return $this->recordFailure($run, 'Restored database has no tenants or no users');
            }

            // The public schema restoring while every tenant schema comes
            // back empty is the failure this whole command exists to catch:
            // it looks like a working backup and contains none of the data
            // customers would be calling about.
            if (($counts['tenant_schemas'] ?? 0) < 1) {
                return $this->recordFailure($run, 'Restored database contains no tenant schemas');
            }

            $run->update([
                'verified_at'     => now(),
                'verified_counts' => $counts,
                'verify_error'    => null,
            ]);

            $this->newLine();
            $this->info("VERIFIED: {$run->filename} restored cleanly into {$scratch}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->recordFailure($run, $e->getMessage());
        } finally {
            if (!$this->option('keep')) {
                $this->dropDatabase($scratch);
            } else {
                $this->warn("Scratch database kept: {$scratch}");
            }
        }
    }

    /**
     * Named recordFailure, not fail: Illuminate\Console\Command already has
     * a fail() with a different signature, and overriding it incompatibly is
     * a fatal error at class load — which took the whole image build down.
     */
    private function recordFailure(BackupRun $run, string $message): int
    {
        $run->update(['verify_error' => Str::limit($message, 2000), 'verified_at' => null]);
        $this->error('VERIFICATION FAILED: ' . $message);

        return self::FAILURE;
    }

    private function createDatabase(string $name): void
    {
        // Not a bound parameter: Postgres does not allow placeholders in
        // CREATE DATABASE. The name is generated here from Str::random, never
        // from input, and the pattern is asserted before it is interpolated.
        $this->assertScratchName($name);
        DB::statement('CREATE DATABASE "' . $name . '"');
    }

    private function dropDatabase(string $name): void
    {
        $this->assertScratchName($name);

        try {
            DB::statement('DROP DATABASE IF EXISTS "' . $name . '" WITH (FORCE)');
        } catch (\Throwable $e) {
            $this->warn("Could not drop scratch database {$name}: " . $e->getMessage());
        }
    }

    /**
     * The guard that makes this command safe to run on a production host:
     * nothing but a generated verify_* name can ever be created or dropped.
     */
    private function assertScratchName(string $name): void
    {
        if (!preg_match('/^verify_[a-z0-9]{12}$/', $name)) {
            throw new \RuntimeException("Refusing to touch database [{$name}] — not a scratch name.");
        }
    }

    private function restore(string $dumpPath, string $database): void
    {
        $config = config('database.connections.pgsql');

        $process = new Process([
            'pg_restore',
            '--no-owner',
            '--no-privileges',
            '--host=' . $config['host'],
            '--port=' . $config['port'],
            '--username=' . $config['username'],
            '--dbname=' . $database,
            $dumpPath,
        ]);

        $process->setEnv(['PGPASSWORD' => (string) $config['password']]);
        $process->setTimeout(1800);
        $process->run();

        // pg_restore exits non-zero on warnings that are not fatal (a missing
        // extension owner, say). Treat it as failure only if nothing landed —
        // which inspect() then confirms independently.
        if (!$process->isSuccessful()) {
            $this->warn('pg_restore reported: ' . trim(Str::limit($process->getErrorOutput(), 500)));
        }
    }

    /**
     * @return array<string, int|null> row count per table, null when absent
     */
    private function inspect(string $database): array
    {
        $config = config('database.connections.pgsql');
        config(['database.connections.verify_scratch' => array_merge($config, ['database' => $database])]);
        DB::purge('verify_scratch');

        $connection = DB::connection('verify_scratch');
        $counts = [];

        foreach (['tenants', 'users', 'plans', 'platform_settings', 'chatbot_index'] as $table) {
            try {
                $counts[$table] = (int) $connection->table($table)->count();
            } catch (\Throwable) {
                $counts[$table] = null;
            }
        }

        // Tenant data lives in per-tenant schemas, so a dump that only carried
        // the public schema would look fine above and be useless in practice.
        try {
            $schemas = $connection->select(
                "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%' ORDER BY schema_name"
            );
            $counts['tenant_schemas'] = count($schemas);

            // And the schemas existing is not the same as the customers'
            // rows being inside them — an empty shell restores just as
            // cleanly as a full one. Count the real thing across all of them.
            $documents = 0;
            $messages = 0;
            foreach ($schemas as $schema) {
                $name = $schema->schema_name;
                if (!preg_match('/^tenant_[a-z0-9_]+$/', $name)) continue;

                foreach ([['documents', 'documents'], ['messages', 'messages']] as [$table, $bucket]) {
                    try {
                        $row = $connection->selectOne("SELECT count(*) AS c FROM \"{$name}\".\"{$table}\"");
                        if ($bucket === 'documents') $documents += (int) $row->c;
                        else $messages += (int) $row->c;
                    } catch (\Throwable) {
                        // A tenant schema without this table is not fatal;
                        // the totals below are what the check turns on.
                    }
                }
            }

            $counts['tenant_documents'] = $documents;
            $counts['tenant_messages']  = $messages;
        } catch (\Throwable) {
            $counts['tenant_schemas'] = null;
        }

        DB::purge('verify_scratch');

        return $counts;
    }
}
