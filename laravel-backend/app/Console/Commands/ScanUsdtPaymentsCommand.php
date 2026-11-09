<?php
namespace App\Console\Commands;

use App\Services\Crypto\{RpcUnavailable, UsdtScanner};
use Illuminate\Console\Command;

/** Reads USDT (BEP20) payments to the receiving address and credits final ones — see UsdtScanner. */
class ScanUsdtPaymentsCommand extends Command
{
    protected $signature = 'crypto:scan';
    protected $description = 'Scan BNB Smart Chain for USDT payments to the receiving address and credit final ones';

    public function handle(UsdtScanner $scanner): int
    {
        try {
            $r = $scanner->scan();
        } catch (RpcUnavailable $e) {
            $this->error('No BSC RPC answered: ' . $e->getMessage());
            return self::FAILURE;
        }
        if (isset($r['skipped'])) {
            $this->line("Skipped: {$r['skipped']}.");
            return self::SUCCESS;
        }
        $this->info(sprintf('Blocks %d–%d: recorded %d, credited %d, unmatched %d, reorged %d.',
            $r['scanned_from'], $r['scanned_to'], $r['recorded'], $r['credited'], $r['unmatched'], $r['reorged']));
        return self::SUCCESS;
    }
}
