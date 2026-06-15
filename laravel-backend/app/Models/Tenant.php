<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Tenant extends Model {
    use HasUuid, SoftDeletes;
    protected $fillable = ['slug','name','email','phone','timezone','language','schema_name','plan_id','status','trial_ends_at','usage_tokens_current','usage_messages_current','settings','last_active_at'];
    protected $casts = ['trial_ends_at'=>'datetime','settings'=>'array','last_active_at'=>'datetime'];
    public function plan()         { return $this->belongsTo(Plan::class); }
    public function users()        { return $this->hasMany(User::class); }
    public function apiKeys()      { return $this->hasMany(ApiKey::class); }
    public function subscription() { return $this->hasOne(Subscription::class)->latestOfMany(); }
    public function isAccessible(): bool { return in_array($this->status, ['active','trial']); }
    public function isTokenQuotaExceeded(): bool { return $this->usage_tokens_current >= ($this->plan->max_tokens_monthly ?? PHP_INT_MAX); }
    public function getWebhookSecret(): ?string { return $this->settings['webhook_secret'] ?? null; }
}
