<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid, HasTenant};

class Chatbot extends Model {
    use HasUuid, HasTenant;
    protected $fillable = ['name','business_name','business_profile','type','status','system_prompt','welcome_message','fallback_response','authenticity_unknown_message','max_payment_link_amount','embedding_model','llm_model','temperature','max_tokens_response','retrieval_top_k','retrieval_threshold','reranker_enabled','rerank_threshold','memory_window','widget_config','notification_settings','enabled_tools','sync_settings','language','response_language','is_active'];
    protected $casts = ['widget_config'=>'array','notification_settings'=>'array','enabled_tools'=>'array','sync_settings'=>'array','business_profile'=>'array','is_active'=>'boolean','reranker_enabled'=>'boolean','temperature'=>'float','retrieval_threshold'=>'float','rerank_threshold'=>'float','max_payment_link_amount'=>'float'];

    /** Which business_profile fields the onboarding checklist (portal dashboard + WidgetSettings) treats as "basic" — see businessProfileMissingCore(). */
    public const CORE_PROFILE_FIELDS = ['address', 'phones_or_emails', 'working_hours', 'description'];
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

    /**
     * Renders business_profile into a fixed block always injected into the
     * system prompt (see ChatService::gatewayPayload()) — never left to
     * retrieval, so "where is your office" works even when no indexed page
     * happens to contain that exact sentence. Both fa and en values are
     * included for any bilingual field that has either, labelled, so the
     * model can draw from whichever matches the conversation's actual
     * language rather than this chatbot's own admin-configured default
     * (the same $conv->language vs $chatbot->language distinction fixed in
     * ChatService — a profile written in Persian must still answer an
     * English-speaking visitor correctly, in English).
     *
     * The closing instruction is what stops a profile gap from being a dead
     * end: the model is told to offer whatever real contact info *is*
     * known instead of a bare "we don't have that, contact us". The hard
     * rule ("state only these exact facts") is what stops it inventing an
     * address from scraped page content when a field is empty.
     */
    public function businessProfilePromptBlock(): ?string
    {
        $p = (array) ($this->business_profile ?? []);
        $lines = [];

        $bilingual = function (string $key, string $label) use ($p, &$lines) {
            $fa = trim((string) ($p[$key]['fa'] ?? ''));
            $en = trim((string) ($p[$key]['en'] ?? ''));
            if ($fa === '' && $en === '') return;
            $parts = array_filter([
                $fa !== '' ? "{$label} (fa): {$fa}" : null,
                $en !== '' ? "{$label} (en): {$en}" : null,
            ]);
            $lines[] = implode(' | ', $parts);
        };

        if (filled($this->business_name)) $lines[] = "Business name: {$this->business_name}";
        $bilingual('description', 'About');
        $bilingual('address', 'Address');
        $bilingual('city', 'City');
        $bilingual('province', 'Province');
        if (filled($p['postal_code'] ?? null)) $lines[] = "Postal code: {$p['postal_code']}";
        if (!empty($p['phones'])) $lines[] = 'Phone: ' . implode(', ', (array) $p['phones']);
        if (!empty($p['emails'])) $lines[] = 'Email: ' . implode(', ', (array) $p['emails']);
        if (!empty($p['working_hours_schedule'])) {
            $lines[] = \App\Support\BusinessHours::describe(
                (array) $p['working_hours_schedule'],
                \App\Support\Settings::get('system.default_timezone'),
                $p['working_hours_exceptions']['fa'] ?? null,
                $p['working_hours_exceptions']['en'] ?? null,
            );
        }
        if (!empty($p['social_links'])) {
            $links = collect((array) $p['social_links'])->filter()->map(fn ($url, $k) => "{$k}: {$url}")->implode(', ');
            if ($links !== '') $lines[] = "Social: {$links}";
        }
        $bilingual('support_channel', 'Support channel');
        if (filled($p['founded_year'] ?? null)) $lines[] = "Founded: {$p['founded_year']}";
        $bilingual('service_area', 'Service/delivery area');
        $bilingual('payment_methods', 'Payment methods');
        $bilingual('return_policy_summary', 'Return policy');
        $bilingual('warranty_summary', 'Warranty');

        if (empty($lines)) return null;

        $knownContact = match (true) {
            !empty($p['phones']) => 'the phone number ' . $p['phones'][0],
            !empty($p['emails']) => 'the email ' . $p['emails'][0],
            default => null,
        };

        return "BUSINESS PROFILE (authoritative — state only these exact facts verbatim; never invent, guess, or infer any of this from page content):\n"
            . implode("\n", $lines)
            . "\n\nIf asked something not covered above and nothing relevant was retrieved, do not just say you do not have that information as a dead end."
            . ($knownContact ? " Offer {$knownContact} instead." : ' Say plainly that it is not listed.')
            . ' If a relevant page WAS retrieved, point to it rather than guessing.';
    }

    /**
     * Which of the "basic" profile fields (see CORE_PROFILE_FIELDS) are
     * still empty — drives the onboarding warning in both WidgetSettings
     * and the customer portal dashboard. Every new chatbot starts here:
     * this is the exact wall every merchant hits (see the business-profile
     * feature's own motivation), so the warning has to be visible before
     * the first real customer question hits it, not after.
     *
     * @return string[] subset of CORE_PROFILE_FIELDS that's still empty
     */
    public function businessProfileMissingCore(): array
    {
        $p = (array) ($this->business_profile ?? []);
        $missing = [];

        if (blank($p['address']['fa'] ?? null) && blank($p['address']['en'] ?? null)) {
            $missing[] = 'address';
        }
        if (empty($p['phones']) && empty($p['emails'])) {
            $missing[] = 'phones_or_emails';
        }
        if (empty($p['working_hours_schedule'])) {
            $missing[] = 'working_hours';
        }
        if (blank($p['description']['fa'] ?? null) && blank($p['description']['en'] ?? null)) {
            $missing[] = 'description';
        }

        return $missing;
    }
}
