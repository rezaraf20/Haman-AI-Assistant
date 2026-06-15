<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class ApiKey extends Model {
    use HasUuid;
    protected $fillable = ['tenant_id','created_by','name','key_prefix','key_hash','scopes','last_used_at','last_used_ip','expires_at','is_active'];
    protected $hidden = ['key_hash'];
    protected $casts = ['scopes'=>'array','is_active'=>'boolean','last_used_at'=>'datetime','expires_at'=>'datetime'];
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function isExpired(): bool { return $this->expires_at && $this->expires_at->isPast(); }
    public function scopeActive($q) { return $q->where('is_active', true); }
}
