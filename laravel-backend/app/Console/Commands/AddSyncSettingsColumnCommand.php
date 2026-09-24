<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time schema migration: adds chatbots.sync_settings to every EXISTING
 * tenant schema.
 *
 * Tenant tables are not Laravel migrations — each tenant's schema is created
 * once, from the literal CREATE TABLE in TenantService::createTenantTables(),
 * at signup. Adding a column there only reaches tenants created from then on;
 * every schema that already existed needs the same column added by hand,
 * once, here. ADD COLUMN IF NOT EXISTS makes this safe to run more than once
 * (a second run, or a tenant created between deploy and this running, is a
 * no-op rather than an error).
 */
class AddSyncSettingsColumnCommand extends Command
{
    protected $signature = 'haman:add-sync-settings-column
        {--tenant= : Only this tenant id or schema name}';

    protected $description = 'Add the chatbots.sync_settings column to every existing tenant schema';

    private const DEFAULT_JSON = '{"sync_products":true,"sync_pages":true,"sync_posts":false,"excluded_category_ids":[],"excluded_page_ids":[]}';

    public function handle(): int
    {
        $only = $this->option('tenant');

        DB::statement('SET search_path TO public');
        $tenants = Tenant::query()
            ->when($only, fn ($q) => $q->where('id', $only)->orWhere('schema_name', $only))
            ->get(['id', 'name', 'schema_name']);

        if ($tenants->isEmpty()) {
            $this->error($only ? "No tenant matches {$only}." : 'No tenants.');
            return self::FAILURE;
        }

        $changed = 0;
        foreach ($tenants as $tenant) {
            try {
                DB::statement(
                    "ALTER TABLE {$tenant->schema_name}.chatbots " .
                    "ADD COLUMN IF NOT EXISTS sync_settings JSONB NOT NULL DEFAULT '" . self::DEFAULT_JSON . "'"
                );
                $this->line("  {$tenant->schema_name}: OK");
                $changed++;
            } catch (\Throwable $e) {
                $this->warn("  {$tenant->schema_name}: {$e->getMessage()}");
            } finally {
                DB::statement('SET search_path TO public');
            }
        }

        $this->info("Done — {$changed}/{$tenants->count()} tenant schema(s) processed.");
        return self::SUCCESS;
    }
}
