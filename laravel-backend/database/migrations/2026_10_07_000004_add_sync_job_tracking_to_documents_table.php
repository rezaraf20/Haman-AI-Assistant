<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a sync job know, precisely, which documents it dispatched for
 * embedding — so it can stop lying about being "completed" the instant
 * those EmbedDocumentJob dispatches are fired, before any of them have
 * actually run. See SyncService's own docblock and EmbedDocumentJob::
 * maybeCloseOutSyncJob() for the other half of this.
 *
 * Runs per tenant schema, same pattern as every other tenant-table
 * migration in this project — documents lives in each tenant's own
 * Postgres schema, not the public one.
 */
return new class extends Migration {
    public function up(): void
    {
        $tenants = DB::table('tenants')->whereNull('deleted_at')->get(['schema_name']);

        foreach ($tenants as $tenant) {
            DB::statement("ALTER TABLE {$tenant->schema_name}.documents ADD COLUMN IF NOT EXISTS sync_job_id UUID");
            DB::statement("CREATE INDEX IF NOT EXISTS documents_sync_job_id_index ON {$tenant->schema_name}.documents (sync_job_id)");
        }
    }

    public function down(): void
    {
        $tenants = DB::table('tenants')->whereNull('deleted_at')->get(['schema_name']);

        foreach ($tenants as $tenant) {
            DB::statement("ALTER TABLE {$tenant->schema_name}.documents DROP COLUMN IF EXISTS sync_job_id");
        }
    }
};
