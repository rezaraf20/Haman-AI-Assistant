<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class WalletTransaction extends Model {
    use HasUuid;
    protected $fillable = [
        'tenant_id', 'type', 'amount_toman', 'balance_after_toman', 'status',
        'gateway', 'gateway_authority', 'gateway_ref_id', 'description',
        'metadata', 'created_by',
    ];
    protected $casts = ['metadata' => 'array'];
    public function tenant() { return $this->belongsTo(Tenant::class); }
}
