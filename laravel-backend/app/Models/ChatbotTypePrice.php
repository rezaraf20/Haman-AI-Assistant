<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class ChatbotTypePrice extends Model {
    use HasUuid;
    protected $fillable = ['type', 'name', 'name_en', 'price_toman', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function scopeActive($q) { return $q->where('is_active', true); }

    /** English falls back to the (required) Persian name when nobody has translated it yet. */
    public function getDisplayNameAttribute(): string {
        return app()->getLocale() === 'en' && filled($this->name_en) ? $this->name_en : $this->name;
    }
}
