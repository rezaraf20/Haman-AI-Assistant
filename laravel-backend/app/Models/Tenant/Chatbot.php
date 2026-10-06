<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid, HasTenant};

class Chatbot extends Model {
    use HasUuid, HasTenant;
    protected $fillable = ['name','business_name','type','status','system_prompt','welcome_message','fallback_response','authenticity_unknown_message','max_payment_link_amount','embedding_model','llm_model','temperature','max_tokens_response','retrieval_top_k','retrieval_threshold','reranker_enabled','rerank_threshold','memory_window','widget_config','notification_settings','enabled_tools','sync_settings','language','response_language','is_active'];
    protected $casts = ['widget_config'=>'array','notification_settings'=>'array','enabled_tools'=>'array','sync_settings'=>'array','is_active'=>'boolean','reranker_enabled'=>'boolean','temperature'=>'float','retrieval_threshold'=>'float','rerank_threshold'=>'float','max_payment_link_amount'=>'float'];
    public function domains()       { return $this->hasMany(ChatbotDomain::class); }
    public function documents()     { return $this->hasMany(Document::class); }
    public function conversations() { return $this->hasMany(Conversation::class); }
    public function syncJobs()      { return $this->hasMany(SyncJob::class); }
    public function products()      { return $this->hasMany(Product::class); }
    public function faqs()          { return $this->hasMany(Faq::class); }
    public function leads()         { return $this->hasMany(Lead::class); }
    public function scopeActive($q) { return $q->where('is_active', true); }

    /**
     * The one true answer to "which tools can this chatbot actually call
     * right now" — the intersection of what the merchant switched on
     * (enabled_tools, this column) and what the tenant's current plan
     * allows (plans.allowed_tools). Every consumer of enabled_tools must
     * call this instead of reading the column directly, or the plan-level
     * gate simply does not exist for that call site — see
     * LivewireActionAuditTest's sibling concern, "a check that only exists
     * in one of several places is not a check".
     *
     * Deliberately NOT cached: a plan change (upgrade, downgrade, trial
     * expiry) must take effect on the very next request, not after a
     * service restart or a TTL — see TrialDowngradeCommand.
     */
    public function effectiveTools(?\App\Models\Tenant $tenant = null): array
    {
        $tenant ??= app()->bound('current_tenant') ? app('current_tenant') : null;
        $allowed = $tenant?->plan?->allowed_tools ?? [];

        // NULL (never configured, distinct from an explicit []) means the
        // merchant has never opened Widget Settings — fall back to each
        // tool's own default_enabled flag rather than treating it as "none
        // selected". See TenantService::createTenantTables()'s enabled_tools
        // column comment and ChatbotTools::defaultEnabledNames().
        $selected = $this->enabled_tools ?? \App\Support\ChatbotTools::defaultEnabledNames();

        return array_values(array_intersect($selected, $allowed));
    }
}
