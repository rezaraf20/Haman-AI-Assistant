<?php
namespace App\Models\Crypto;

use App\Models\Tenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A tenant's request to top up the USDT wallet: send exactly amount_micro to address before expires_at. */
class CryptoDeposit extends Model
{
    use HasUuid;

    protected $fillable = ['tenant_id', 'created_by', 'chain_id', 'token_contract', 'address', 'requested_micro', 'amount_micro',
        'status', 'expires_at', 'paid_at', 'crypto_transfer_id'];
    protected $casts = ['requested_micro' => 'integer', 'amount_micro' => 'integer', 'expires_at' => 'datetime', 'paid_at' => 'datetime'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function transfer() { return $this->belongsTo(CryptoTransfer::class, 'crypto_transfer_id'); }
}
