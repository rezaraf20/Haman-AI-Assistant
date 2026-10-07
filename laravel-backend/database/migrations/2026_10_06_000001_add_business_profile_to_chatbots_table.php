<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * chatbots.business_profile — see TenantService::createTenantTables()'s
 * matching column comment for the full field list and why it's injected
 * into the system prompt always, never left to retrieval.
 *
 * Runs per tenant schema, same pattern as 2026_10_01_000001 (enabled_tools
 * nullable) -- chatbots lives in each tenant's own Postgres schema, not the
 * public one. A genuinely fresh install gets this column from
 * TenantService::createTenantTables() directly; this migration is what
 * reaches an already-deployed tenant's existing schema.
 */
return new class extends Migration {
    public function up(): void
    {
        $tenants = DB::table('tenants')->whereNull('deleted_at')->get(['schema_name']);

        foreach ($tenants as $tenant) {
            DB::statement("ALTER TABLE {$tenant->schema_name}.chatbots ADD COLUMN IF NOT EXISTS business_profile JSONB NOT NULL DEFAULT '{}'");
        }
    }

    public function down(): void
    {
        $tenants = DB::table('tenants')->whereNull('deleted_at')->get(['schema_name']);

        foreach ($tenants as $tenant) {
            DB::statement("ALTER TABLE {$tenant->schema_name}.chatbots DROP COLUMN IF EXISTS business_profile");
        }
    }
};
