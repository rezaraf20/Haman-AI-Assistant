<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class ChatbotTypePrice extends Model {
    use HasUuid;
    protected $fillable = ['type', 'name', 'price_toman', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function scopeActive($q) { return $q->where('is_active', true); }
}
