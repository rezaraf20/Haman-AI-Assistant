<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\{TenantService, TenantSchemaBackupService};
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * The only thing that turns TenantResource's "mark for deletion" into a
 * real, irreversible DROP SCHEMA — and only once the grace period
 * (Settings 'limits.tenant_deletion_grace_days') has actually elapsed. A
 * tenant that clicked "restore" before this runs is never touched: this
 * only ever looks at pending_deletion_at, which restoreFromDeletion()
 * clears.
 */
class DropPendingDeletionTenantsCommand extends Command
{
    protected $signature = 'haman:drop-pending-deletion-tenants';
    protected $description = 'Backup and drop the schema of every tenant whose deletion grace period has elapsed';

    public function handle(TenantService $tenants, TenantSchemaBackupService $backups): int
    {
        $graceDays = (int) Settings::get('limits.tenant_deletion_grace_days');
        $cutoff = now()->subDays($graceDays);

        $due = Tenant::whereNotNull('pending_deletion_at')
            ->where('pending_deletion_at', '<=', $cutoff)
            ->get();

        foreach ($due as $tenant) {
            try {
                $backupPath = $tenants->permanentlyDeleteNow($tenant, $backups);

                \App\Support\PlatformActivity::record(
                    'tenant_deletion_grace_period_elapsed',
                    tenantId: (string) $tenant->id,
                    subjectType: 'tenant',
                    subjectId: (string) $tenant->id,
                    after: ['schema_name' => $tenant->schema_name, 'backup' => basename($backupPath)],
                );

                $this->line("Dropped {$tenant->schema_name} ({$tenant->email}) — backup: " . basename($backupPath));
            } catch (\Throwable $e) {
                $this->error("Failed to drop {$tenant->schema_name} ({$tenant->email}): {$e->getMessage()}");
                report($e);
            }
        }

        $pruned = $backups->prune();
        if ($pruned) {
            $this->line(count($pruned) . ' old deletion backup(s) pruned.');
        }

        $this->info("{$due->count()} tenant(s) past their grace period.");

        return self::SUCCESS;
    }
}
