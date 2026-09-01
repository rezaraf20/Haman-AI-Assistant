<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class TokenPackage extends Model {
    use HasUuid;
    protected $fillable = ['name', 'chatbot_type', 'token_amount', 'price_toman', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function scopeActive($q) { return $q->where('is_active', true); }
}
