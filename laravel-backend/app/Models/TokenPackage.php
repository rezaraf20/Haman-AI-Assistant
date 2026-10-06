<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class TokenPackage extends Model {
    use HasUuid;
    protected $fillable = ['name', 'name_en', 'chatbot_type', 'token_amount', 'bonus_percent', 'price_toman', 'is_active', 'sort_order'];
    protected $casts = ['is_active' => 'boolean', 'bonus_percent' => 'float'];
    public function scopeActive($q) { return $q->where('is_active', true); }

    /** English falls back to the (required) Persian name when nobody has translated it yet — same pattern as Plan::getDisplayNameAttribute(). */
    public function getDisplayNameAttribute(): string {
        return app()->getLocale() === 'en' && filled($this->name_en) ? $this->name_en : $this->name;
    }
}
