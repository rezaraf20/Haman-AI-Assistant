<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Plan extends Model {
    use HasUuid;
    protected $fillable = ['name','name_en','slug','description','description_en','price_monthly','price_yearly','max_chatbots','max_tokens_monthly','max_documents','max_messages_monthly','max_domains','model_tier','features','allowed_tools','can_purchase_tokens','branding_removable','quota_exceeded_behavior','is_active','is_public','sort_order'];
    protected $casts = ['features'=>'array','allowed_tools'=>'array','is_active'=>'boolean','is_public'=>'boolean','can_purchase_tokens'=>'boolean','branding_removable'=>'boolean','price_monthly'=>'float'];

    // Below this, a paid, publicly-listed plan is almost certainly still the
    // seed value (29/99/299 — plain USD-scale numbers PlanSeeder wrote
    // before any admin set a real Toman price) rather than something anyone
    // chose deliberately. Free (0) is a real, intentional price and never
    // flagged. Used by both the admin list/form warning and
    // LandingController's "coming soon" fallback, so the two can't drift.
    public const PRICE_SANITY_THRESHOLD = 1000;

    public function tenants() { return $this->hasMany(Tenant::class); }
    public function scopeActive($q) { return $q->where('is_active',true)->orderBy('sort_order'); }

    /** English falls back to the (required) Persian name when nobody has translated it yet. */
    public function getDisplayNameAttribute(): string {
        return app()->getLocale() === 'en' && filled($this->name_en) ? $this->name_en : $this->name;
    }

    public function getDisplayDescriptionAttribute(): ?string {
        return app()->getLocale() === 'en' && filled($this->description_en) ? $this->description_en : $this->description;
    }

    /**
     * Each entry is `['fa' => ..., 'en' => ...]` (the Repeater's own state
     * shape — see PlanResource::form()). Anything else (the pre-cleanup
     * `{"woocommerce": true}` shape, a stray plain string, a blank line) is
     * dropped rather than printed raw, which is what let a bare "1" reach
     * the public pricing card in the first place.
     */
    public function getDisplayFeaturesAttribute(): array {
        $locale = app()->getLocale();

        return collect($this->features ?? [])
            ->map(function ($feature) use ($locale) {
                if (is_string($feature)) return trim($feature);
                if (!is_array($feature)) return null;

                // filled(), not ?? — an empty string (a form field left
                // blank, which Filament sometimes dehydrates as '' rather
                // than null) must fall back exactly like an unset one does.
                $primary = $feature[$locale === 'en' ? 'en' : 'fa'] ?? null;
                $fallback = $feature[$locale === 'en' ? 'fa' : 'en'] ?? null;
                $text = filled($primary) ? $primary : $fallback;

                return is_string($text) ? trim($text) : null;
            })
            ->filter(fn ($text) => filled($text))
            ->values()
            ->all();
    }

    /** A paid plan, published on the public site, priced like nobody has touched it since PlanSeeder ran. */
    public function looksLikeDefaultPrice(): bool {
        return $this->is_active && $this->is_public
            && $this->price_monthly > 0
            && $this->price_monthly < self::PRICE_SANITY_THRESHOLD;
    }
}
