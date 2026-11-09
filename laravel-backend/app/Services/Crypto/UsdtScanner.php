<?php
namespace App\Services\Crypto;

use App\Models\Crypto\{CryptoDeposit, CryptoTransfer};
use App\Models\User;
use App\Services\WalletService;
use App\Support\{Settings, Usdt};
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Log};

/**
 * Finds USDT payments to the receiving address and credits them.
 *
 * Runs every minute (crypto:scan). Each run:
 *
 *  1. reads the chain head and the finalized block (BSC fast finality;
 *     `crypto.bsc_finality_depth` blocks behind head if the RPC lacks the
 *     tag);
 *  2. reads Transfer logs of the USDT contract whose recipient is the
 *     receiving address, from just after the last FINAL block already read
 *     up to the head — the not-yet-final tail is read again on every run,
 *     so a reorganisation cannot hide a payment, and the unique
 *     (chain, tx_hash, log_index) key makes re-reading harmless;
 *  3. for every recorded transfer whose block is now final: fetches the
 *     receipt and checks it is still in the same block (same block hash),
 *     succeeded, and contains this exact log — otherwise it was reorged
 *     away and is never credited;
 *  4. matches it to a request by exact amount and credits the tenant's USDT
 *     wallet, once (the ledger's crypto_transfer_id is unique).
 *
 * Nothing is credited before finality, nothing on a customer's word (a
 * pasted tx hash proves nothing here), nothing from another token or
 * another chain (the log filter names the contract; BscRpc refuses any
 * endpoint that is not chain 56).
 */
class UsdtScanner
{
    public const CHECKPOINT = 'bsc-usdt';
    private const CHUNK = 500;           // blocks per eth_getLogs (publicnode serves 2000; 500 leaves headroom)
    private const MAX_CHUNKS = 40;       // per run — catches up ~4h of blocks a minute after an outage

    /** @var array<int, int> block number => unix time */
    private array $blockTimes = [];

    public function __construct(
        private BscRpc $rpc,
        private UsdtDeposits $deposits,
        private WalletService $wallet,
    ) {}

    /** @return array{scanned_from?:int, scanned_to?:int, recorded:int, credited:int, unmatched:int, reorged:int, skipped?:string} */
    public function scan(): array
    {
        $result = ['recorded' => 0, 'credited' => 0, 'unmatched' => 0, 'reorged' => 0];
        $this->deposits->expireOld();
        if (!UsdtDeposits::enabled()) {
            return $result + ['skipped' => 'disabled'];
        }
        $address = UsdtDeposits::address();
        $cp = DB::table('chain_checkpoints')->where('name', self::CHECKPOINT)->first();

        try {
            $head = $this->rpc->blockNumber();
            $finalized = $this->rpc->finalizedBlock() ?? max(0, $head - max(1, (int) Settings::get('crypto.bsc_finality_depth')));
            $finalized = min($finalized, $head);

            // First run: start a little before the final block — no request
            // can predate the feature being switched on.
            $from = $cp && $cp->last_scanned_block !== null ? (int) $cp->last_scanned_block + 1 : max(0, $finalized - 50);
            $to = min($head, $from + self::CHUNK * self::MAX_CHUNKS - 1);

            for ($a = $from; $a <= $to; $a += self::CHUNK) {
                $b = min($to, $a + self::CHUNK - 1);
                foreach ($this->rpc->transferLogs($a, $b, $address) as $log) {
                    if ($this->record($log, $address)) $result['recorded']++;
                }
            }
            // Everything up to the final block within what was read is done
            // for good; the tail beyond it is read again next minute.
            $scannedFinal = $to >= $from ? min($finalized, $to) : ($cp?->last_scanned_block);

            foreach (CryptoTransfer::where('status', 'detected')->where('block_number', '<=', $finalized)->orderBy('block_number')->get() as $transfer) {
                $outcome = $this->finalize($transfer);
                if (isset($result[$outcome])) $result[$outcome]++;
            }

            $this->checkpoint([
                'last_scanned_block' => $scannedFinal,
                'head_block' => $head, 'finalized_block' => $finalized,
                'provider' => parse_url((string) $this->rpc->lastEndpoint, PHP_URL_HOST),
                'heartbeat_at' => now(), 'last_error' => null,
            ]);
            return $result + ['scanned_from' => $from, 'scanned_to' => $to];
        } catch (RpcUnavailable $e) {
            $this->checkpoint(['last_error' => $e->getMessage(), 'last_error_at' => now()]);
            Log::warning('crypto:scan — no BSC RPC answered: ' . $e->getMessage());
            throw $e;
        }
    }

    private function checkpoint(array $values): void
    {
        $values = array_filter($values, fn ($v, $k) => $v !== null || in_array($k, ['last_error'], true), ARRAY_FILTER_USE_BOTH);
        $exists = DB::table('chain_checkpoints')->where('name', self::CHECKPOINT)->exists();
        if ($exists) {
            DB::table('chain_checkpoints')->where('name', self::CHECKPOINT)->update($values + ['updated_at' => now()]);
        } else {
            DB::table('chain_checkpoints')->insert($values + ['name' => self::CHECKPOINT, 'chain_id' => Usdt::CHAIN_ID,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Stores one Transfer log; false when it was already known or is not a USDT transfer to us. */
    private function record(array $log, string $address): bool
    {
        if (!empty($log['removed'])) return false;
        if (strtolower($log['address'] ?? '') !== Usdt::CONTRACT) return false;
        $topics = $log['topics'] ?? [];
        if (count($topics) !== 3 || strtolower($topics[0]) !== Usdt::TRANSFER_TOPIC) return false;
        if (Usdt::topicToAddress($topics[2]) !== $address) return false;

        $block = (int) hexdec($log['blockNumber']);
        $time = $this->blockTimes[$block] ??= (int) $this->rpc->blockTime($block);

        return DB::table('crypto_transfers')->insertOrIgnore([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'chain_id' => Usdt::CHAIN_ID,
            'token_contract' => Usdt::CONTRACT,
            'tx_hash' => strtolower($log['transactionHash']),
            'log_index' => (int) hexdec($log['logIndex']),
            'block_number' => $block,
            'block_hash' => strtolower($log['blockHash']),
            'block_time' => $time ? Carbon::createFromTimestamp($time) : null,
            'from_address' => Usdt::topicToAddress($topics[1]),
            'to_address' => $address,
            'amount_base' => Usdt::hexToDec($log['data']),
            'status' => 'detected',
            'created_at' => now(), 'updated_at' => now(),
        ]) > 0;
    }

    /** @return string credited | unmatched | reorged | ignored */
    public function finalize(CryptoTransfer $transfer): string
    {
        $receipt = $this->rpc->receipt($transfer->tx_hash);
        if ($receipt && strtolower($receipt['blockHash'] ?? '') === $transfer->block_hash && ($receipt['status'] ?? '0x1') !== '0x1') {
            $transfer->update(['status' => 'ignored', 'note' => 'transaction reverted']);
            return 'ignored';
        }

        $stillThere = $receipt
            && strtolower($receipt['blockHash'] ?? '') === $transfer->block_hash
            && collect($receipt['logs'] ?? [])->contains(fn ($l) =>
                (int) hexdec($l['logIndex'] ?? '0x0') === $transfer->log_index
                && strtolower($l['address'] ?? '') === Usdt::CONTRACT
                && Usdt::hexToDec($l['data'] ?? '0x0') === (string) $transfer->amount_base
                && Usdt::topicToAddress($l['topics'][2] ?? '') === $transfer->to_address);

        if (!$stillThere) {
            // Not in the canonical chain any more. If the transaction was
            // re-included elsewhere, the rescan of the tail records it anew.
            $transfer->update(['status' => 'reorged', 'note' => 'not in the final block it was seen in']);
            return 'reorged';
        }
        $transfer->update(['finalized_at' => now()]);
        return $this->credit($transfer);
    }

    /** Matches a final transfer to a request by exact amount and credits it. */
    private function credit(CryptoTransfer $transfer): string
    {
        $outcome = DB::transaction(function () use ($transfer) {
            $locked = CryptoTransfer::where('id', $transfer->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'detected') return 'skip';

            $deposit = $this->match($locked);
            if (!$deposit) {
                $locked->update(['status' => 'unmatched',
                    'note' => Usdt::isWholeMicro((string) $locked->amount_base) ? 'no request for this exact amount' : 'amount has more than 6 decimals']);
                return 'unmatched';
            }

            $micro = $locked->amountMicro();
            $ledger = $this->wallet->applyUsdt($deposit->tenant_id, $micro, 'deposit', [
                'description' => __('usdt.deposit_description', ['tx' => substr($locked->tx_hash, 0, 12) . '…']),
                'metadata' => ['tx_hash' => $locked->tx_hash, 'block' => $locked->block_number, 'deposit_id' => $deposit->id],
                'crypto_transfer_id' => $locked->id,
            ]);
            $deposit->update(['status' => 'paid', 'paid_at' => now(), 'crypto_transfer_id' => $locked->id]);
            $locked->update(['status' => 'credited', 'credited_at' => now(), 'deposit_id' => $deposit->id,
                'tenant_id' => $deposit->tenant_id, 'usdt_wallet_transaction_id' => $ledger->id]);
            return 'credited';
        });

        if ($outcome === 'credited') {
            $this->notifyCredited($transfer->fresh());
        }
        return $outcome === 'skip' ? 'skipped' : $outcome;
    }

    /**
     * The one request this transfer pays: same address, exactly the same
     * amount, made before the block, and the block no later than the
     * request's expiry plus the late-payment grace. Two candidates (should
     * not happen — amounts are not reissued inside that window) means none.
     */
    private function match(CryptoTransfer $transfer): ?CryptoDeposit
    {
        if (!Usdt::isWholeMicro((string) $transfer->amount_base)) return null;
        $micro = $transfer->amountMicro();
        $at = $transfer->block_time ?? now();
        $grace = max(0, (int) Settings::get('crypto.usdt_late_grace_hours'));

        $candidates = CryptoDeposit::where('address', $transfer->to_address)
            ->where('amount_micro', $micro)
            ->whereIn('status', ['pending', 'expired', 'cancelled'])
            ->where('created_at', '<=', $at->copy()->addMinutes(5))
            ->where('expires_at', '>=', $at->copy()->subHours($grace))
            ->lockForUpdate()->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function notifyCredited(CryptoTransfer $transfer): void
    {
        foreach (User::where('tenant_id', $transfer->tenant_id)->get() as $user) {
            Notification::make()
                ->title(__('usdt.credited_title'))
                ->body(__('usdt.credited_body', ['amount' => Usdt::format($transfer->amountMicro())]))
                ->success()->sendToDatabase($user);
        }
    }
}
