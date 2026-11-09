<?php
namespace App\Models\Crypto;

use App\Models\Tenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A USDT Transfer log to the platform's receiving address, as read from the
 * chain. amount_base is the exact on-chain amount (18 decimals) as a
 * decimal string. Unique per (chain, tx_hash, log_index): seen twice, it is
 * still one row, and credited at most once.
 */
class CryptoTransfer extends Model
{
    use HasUuid;

    protected $fillable = ['chain_id', 'token_contract', 'tx_hash', 'log_index', 'block_number', 'block_hash', 'block_time',
        'from_address', 'to_address', 'amount_base', 'status', 'deposit_id', 'tenant_id', 'usdt_wallet_transaction_id',
        'finalized_at', 'credited_at', 'note'];
    protected $casts = ['block_number' => 'integer', 'log_index' => 'integer', 'block_time' => 'datetime',
        'finalized_at' => 'datetime', 'credited_at' => 'datetime'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function deposit() { return $this->belongsTo(CryptoDeposit::class, 'deposit_id'); }

    /** The amount in micro-USDT (rounded down to whole micro). */
    public function amountMicro(): int { return \App\Support\Usdt::baseToMicro((string) $this->amount_base) ?? 0; }
}
