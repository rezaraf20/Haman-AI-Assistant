<?php
namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** One row of a tenant's USDT wallet ledger; amounts in micro-USDT (App\Support\Usdt). Written only by WalletService. */
class UsdtWalletTransaction extends Model
{
    use HasUuid;

    protected $fillable = ['tenant_id', 'type', 'amount_micro', 'balance_after_micro', 'description', 'metadata', 'created_by', 'crypto_transfer_id'];
    protected $casts = ['amount_micro' => 'integer', 'balance_after_micro' => 'integer', 'metadata' => 'array'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function transfer() { return $this->belongsTo(Crypto\CryptoTransfer::class, 'crypto_transfer_id'); }
}
