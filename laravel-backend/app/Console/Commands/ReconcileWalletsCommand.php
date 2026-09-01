<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Tenant;
use App\Services\WalletService;

class ReconcileWalletsCommand extends Command {
    protected $signature   = 'wallet:reconcile';
    protected $description = 'Recompute each tenant\'s cached wallet balance from the wallet_transactions ledger and log any drift';

    public function handle(WalletService $wallet): void {
        $drifted = 0;
        foreach (Tenant::cursor() as $tenant) {
            $result = $wallet->reconcile($tenant);
            if ($result['drift'] !== 0) {
                $drifted++;
                $this->warn("Drift for {$tenant->email}: cached vs ledger differed by {$result['drift']} Toman — corrected.");
            }
        }
        $this->info("Reconciled all tenants. {$drifted} had drift.");
    }
}
