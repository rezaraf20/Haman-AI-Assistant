<?php
namespace App\Console\Commands;

use App\Models\{Tenant, User};
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The other half of the resource-allocation gate — see TenantService::
 * registerViaEmail()/provisionVerifiedTenant(). A tenant whose email is
 * still unverified this long after signup has, under the deferred-
 * provisioning flow, no schema, no chatbot, no API key — nothing but this
 * tenant row and its user. Hard-deletes both (not the two-phase
 * mark-for-deletion lifecycle, which exists for tenants that DO have a
 * schema worth protecting with a grace period): there is nothing here to
 * snapshot or restore, and nothing for a backup to protect.
 *
 * Scoped to provisioned_at IS NULL specifically, never "trial and old" —
 * a tenant that verified and is simply an inactive trial is a real
 * customer's abandoned account, not an unverified signup, and must never
 * be swept up here.
 */
class PurgeUnverifiedSignupsCommand extends Command
{
    protected $signature = 'haman:purge-unverified-signups';
    protected $description = 'Hard-delete tenants that never verified their email within the grace window — safe because they have no schema';

    public function handle(): int
    {
        $days = (int) Settings::get('limits.unverified_signup_purge_days');
        $cutoff = now()->subDays($days);

        $stale = Tenant::whereNull('provisioned_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $tenant) {
            // Belt-and-suspenders: provisioned_at is the real gate, but a
            // schema that somehow exists anyway (a bug, a manual DB edit)
            // must never be silently destroyed by a command whose whole
            // premise is "there is nothing here to lose".
            $hasSchema = DB::selectOne(
                'SELECT 1 FROM information_schema.schemata WHERE schema_name = ?',
                [$tenant->schema_name]
            );
            if ($hasSchema) {
                $this->warn("Skipping {$tenant->email}: provisioned_at is null but schema {$tenant->schema_name} exists — not safe to hard-delete.");
                report(new \RuntimeException("PurgeUnverifiedSignupsCommand: tenant {$tenant->id} has an unexpected schema"));
                continue;
            }

            \App\Support\PlatformActivity::record(
                'unverified_signup_purged',
                tenantId: (string) $tenant->id,
                subjectType: 'tenant',
                subjectId: (string) $tenant->id,
                after: ['email' => $tenant->email, 'created_at' => (string) $tenant->created_at],
            );

            User::where('tenant_id', $tenant->id)->forceDelete();
            $tenant->forceDelete();

            $this->line("Purged unverified signup: {$tenant->email}");
        }

        $this->info("{$stale->count()} unverified signup(s) past the {$days}-day grace window purged.");

        return self::SUCCESS;
    }
}
