<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class LlmProviderProfile extends Model {
    use HasUuid;
    protected $fillable = [
        'name', 'provider', 'base_url', 'model_name', 'api_key',
        'priority', 'is_active', 'max_tokens_response', 'timeout_seconds',
        'extra_headers', 'last_success_at', 'last_failure_at', 'consecutive_failures',
    ];
    protected $casts = [
        'is_active'        => 'boolean',
        'extra_headers'    => 'array',
        'last_success_at'  => 'datetime',
        'last_failure_at'  => 'datetime',
    ];
    public function scopeActive($q) { return $q->where('is_active', true); }
}
