<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two-phase tenant deletion lifecycle — see TenantResource's
 * mark_for_deletion/restore_from_deletion/permanent_delete_now actions and
 * TenantService::markForDeletion()/restoreFromDeletion().
 *
 * pending_deletion_at: null means not scheduled; set means the grace
 * period (Settings 'tenant_lifecycle.deletion_grace_days') is counting
 * down toward DropPendingDeletionTenantsCommand actually dropping the
 * schema. Nothing is destroyed until that command runs — this column
 * alone is what "reversible until the real drop" means.
 *
 * pending_deletion_snapshot: exactly what restoreFromDeletion() needs to
 * put back the way it was — the tenant's own status before it was
 * suspended, and each chatbot_index row's is_active before it was turned
 * off. Without this, "restore" would have no way to tell a chatbot the
 * merchant had already disabled themselves apart from one this action
 * disabled, and would reactivate both the same way.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            if (!Schema::hasColumn('tenants', 'pending_deletion_at')) {
                $t->timestamp('pending_deletion_at')->nullable()->after('deleted_at');
            }
            if (!Schema::hasColumn('tenants', 'pending_deletion_snapshot')) {
                $t->jsonb('pending_deletion_snapshot')->nullable()->after('pending_deletion_at');
            }
        });
    }
    public function down(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['pending_deletion_at', 'pending_deletion_snapshot']);
        });
    }
};
