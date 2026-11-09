<?php
namespace App\Console\Commands;

use App\Models\WalletTransaction;
use App\Services\{PaymentService, WalletService};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Settles gateway top-ups that are still pending: the customer paid but
 * closed the tab before the callback, or the callback could not reach the
 * gateway to verify. Before this nothing ever looked at a pending top-up
 * again — a paid one could stay uncredited indefinitely.
 *
 * Each is asked of its gateway (PaymentService::resolvePending): paid →
 * credited, definitely unpaid → failed, no answer → asked again next run.
 * After `--give-up-hours` without any answer it is failed and logged as a
 * warning, so it lands in front of a human rather than retrying forever.
 */
class ReverifyPendingPaymentsCommand extends Command
{
    protected $signature = 'payments:reverify-pending {--min-age=5 : minutes — leave fresh ones to their own callback} {--give-up-hours=72}';
    protected $description = 'Re-verify pending gateway top-ups with their gateway and settle them';

    public function handle(PaymentService $payments, WalletService $wallet): int
    {
        $counts = ['completed' => 0, 'failed' => 0, 'pending' => 0, 'abandoned' => 0];
        $giveUp = now()->subHours((int) $this->option('give-up-hours'));

        $pending = WalletTransaction::where('status', 'pending')
            ->whereNotNull('gateway')
            ->where('created_at', '<', now()->subMinutes((int) $this->option('min-age')))
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        foreach ($pending as $txn) {
            $outcome = $payments->resolvePending($txn);

            if ($outcome === 'pending' && $txn->created_at->lessThan($giveUp)) {
                $wallet->failPendingTransaction($txn, 'No answer from the gateway within the re-verify window');
                Log::warning("payments:reverify-pending gave up on top-up {$txn->id} ({$txn->gateway} {$txn->gateway_authority}) — check it with the gateway by hand");
                $counts['abandoned']++;
                continue;
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        $this->info(sprintf('Completed %d, failed %d, still pending %d, gave up %d.',
            $counts['completed'], $counts['failed'], $counts['pending'], $counts['abandoned']));
        return self::SUCCESS;
    }
}
