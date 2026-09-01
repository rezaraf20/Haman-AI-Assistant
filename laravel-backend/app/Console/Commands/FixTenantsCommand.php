<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Tenant;
use App\Services\TenantService;

class FixTenantsCommand extends Command {
    protected $signature   = 'hamman:fix-tenants';
    protected $description = 'Fix all tenant schemas (add missing columns)';
    public function handle(TenantService $svc): void {
        $tenants = Tenant::all();
        foreach ($tenants as $tenant) {
            $svc->fixSchema($tenant->schema_name);
            $this->info("Fixed: {$tenant->schema_name}");
        }
        $this->info("Done - {$tenants->count()} tenants fixed");
    }
}
