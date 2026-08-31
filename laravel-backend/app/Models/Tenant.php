<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Tenant extends Model {
    use HasUuid, SoftDeletes;
    protected $fillable = ['slug','name','email','phone','timezone','language','schema_name','plan_id','status','trial_ends_at','usage_tokens_current','usage_messages_current','bonus_tokens','wallet_balance_toman','settings','last_active_at','admin_seen_at'];
    protected $casts = ['trial_ends_at'=>'datetime','settings'=>'array','last_active_at'=>'datetime','admin_seen_at'=>'datetime'];
    public function plan()         { return $this->belongsTo(Plan::class); }
    public function users()        { return $this->hasMany(User::class); }
    // The signup owner — every tenant gets exactly one User at creation time
    // (register() / registerViaPhone() in TenantService), so "oldest" reliably
    // picks that original account even if more users are added later.
    // Plain hasOne + orderBy, not oldestOfMany(): Laravel's "of many" tie-break
    // machinery always adds a MIN/MAX(id) subquery regardless of which column
    // you pass it, and users.id is uuid — Postgres has no MIN/MAX() for uuid,
    // so ofMany()/oldestOfMany() throws on this table no matter what. A plain
    // ordered hasOne has no such tie-break query and works identically for
    // both eager loading (with('owner')) and lazy access ($tenant->owner).
    public function owner()        { return $this->hasOne(User::class)->oldest('created_at'); }
    public function apiKeys()      { return $this->hasMany(ApiKey::class); }
    public function walletTransactions() { return $this->hasMany(WalletTransaction::class); }
    public function subscription() { return $this->hasOne(Subscription::class)->latestOfMany(); }
    public function isAccessible(): bool { return in_array($this->status, ['active','trial']); }
    // Purchased bonus_tokens (Customer\Pages\BuyTokens) only kick in once the
    // plan's own monthly allowance is used up — see TenantService::incrementUsage()
    // for where they actually get drawn down.
    public function isTokenQuotaExceeded(): bool {
        $planLimit = $this->plan->max_tokens_monthly ?? PHP_INT_MAX;
        if ($this->usage_tokens_current < $planLimit) return false;
        return $this->bonus_tokens <= 0;
    }
    public function getWebhookSecret(): ?string { return $this->settings['webhook_secret'] ?? null; }
}
