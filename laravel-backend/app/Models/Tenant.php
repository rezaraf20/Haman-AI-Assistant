<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Tenant extends Model {
    use HasUuid, SoftDeletes;
    protected $fillable = ['slug','name','email','phone','country','timezone','language','schema_name','plan_id','pending_plan_id','pending_plan_effective_at','status','trial_ends_at','trial_reminder_3d_sent_at','trial_reminder_1d_sent_at','quota_period_started_at','usage_tokens_current','usage_messages_current','bonus_tokens','wallet_balance_toman','settings','last_active_at','admin_seen_at','provisioned_at','signup_risk','pending_deletion_at','pending_deletion_snapshot'];
    protected $casts = ['trial_ends_at'=>'datetime','trial_reminder_3d_sent_at'=>'datetime','trial_reminder_1d_sent_at'=>'datetime','quota_period_started_at'=>'datetime','pending_plan_effective_at'=>'datetime','settings'=>'array','last_active_at'=>'datetime','admin_seen_at'=>'datetime','provisioned_at'=>'datetime','signup_risk'=>'array','pending_deletion_at'=>'datetime','pending_deletion_snapshot'=>'array'];
    public function plan()         { return $this->belongsTo(Plan::class); }
    public function pendingPlan()  { return $this->belongsTo(Plan::class, 'pending_plan_id'); }
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
    // AggregateAnalyticsJob calls Tenant::active() but this scope never
    // existed — the job would throw BadMethodCallException the first time it
    // actually ran. Same status set as isAccessible() above.
    public function scopeActive($q) { return $q->whereIn('status', ['active', 'trial']); }
    // Purchased bonus_tokens (Customer\Pages\BuyTokens) only kick in once the
    // plan's own monthly allowance is used up — see TenantService::incrementUsage()
    // for where they actually get drawn down.
    public function isTokenQuotaExceeded(): bool {
        $planLimit = $this->plan->max_tokens_monthly ?? PHP_INT_MAX;
        if ($this->usage_tokens_current < $planLimit) return false;
        return $this->bonus_tokens <= 0;
    }
    public function getWebhookSecret(): ?string { return $this->settings['webhook_secret'] ?? null; }

    /** Reversible right up until DropPendingDeletionTenantsCommand actually runs — see TenantService::markForDeletion(). */
    public function isPendingDeletion(): bool { return $this->pending_deletion_at !== null; }

    /** True only once provisionVerifiedTenant() has actually run — a tenant can exist with no schema at all before this. */
    public function isProvisioned(): bool { return $this->provisioned_at !== null; }

    /** @return string[] flag names set by TenantService::computeSignupRisk() — never a reason to block, only to show the admin which signups to look at twice. */
    public function riskFlags(): array { return array_keys(array_filter((array) ($this->signup_risk['flags'] ?? []))); }
    public function riskScore(): int { return (int) ($this->signup_risk['score'] ?? 0); }

    /**
     * The one thing `country` actually controls: which currency this tenant
     * sees everywhere in their own portal (see App\Support\Money::display()
     * and every Customer\Pages\* page). Iran or unset (the OTP-phone signup
     * path never sets this at all, since it only ever accepts an Iranian
     * mobile number) means Toman; anything else means Euro. This has no
     * effect on the admin panel, which stays Toman-only regardless — see
     * App\Support\Money::toman(), untouched by any of this.
     */
    public function currency(): string
    {
        return \App\Support\Countries::isIran($this->country ?? \App\Support\Countries::IRAN) ? 'IRT' : 'EUR';
    }
}
