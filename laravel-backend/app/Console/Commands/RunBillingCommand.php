<?php
namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

/**
 * Renewals: issue the next period's invoices, charge the due ones from the
 * wallet, void what is still unpaid after the grace period. Safe to run as
 * often as it likes — every step is idempotent (see BillingService).
 */
class RunBillingCommand extends Command
{
    protected $signature = 'billing:run';
    protected $description = 'Issue renewal invoices, charge due ones from the wallet, and expire unpaid ones';

    public function handle(BillingService $billing): int
    {
        $r = $billing->run();
        $this->info("Issued {$r['issued']}, paid {$r['paid']}, unpaid {$r['unpaid']}, expired {$r['expired']}.");
        return self::SUCCESS;
    }
}
