<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Traits\HasUuid;
use App\Support\LlmKeyCrypto;

class LlmProviderProfile extends Model {
    use HasUuid;
    protected $fillable = [
        'name', 'provider', 'base_url', 'model_name', 'api_key',
        'input_price_per_1m_toman', 'output_price_per_1m_toman',
        'priority', 'is_active', 'max_tokens_response', 'timeout_seconds',
        'extra_headers', 'last_success_at', 'last_failure_at', 'consecutive_failures',
    ];
    protected $casts = [
        'is_active'                  => 'boolean',
        'extra_headers'              => 'array',
        'last_success_at'            => 'datetime',
        'last_failure_at'            => 'datetime',
        'input_price_per_1m_toman'   => 'float',
        'output_price_per_1m_toman'  => 'float',
    ];
    public function scopeActive($q) { return $q->where('is_active', true); }

    // Stored via LlmKeyCrypto (AES-256-GCM, Python-decryptable — see that
    // class), not plaintext. get() falls back to the raw stored value when
    // decryption fails, which covers rows written before this cast existed —
    // they keep working (masked display, Python's own raw-SQL read) exactly
    // as before until next saved through this model, which encrypts them.
    protected function apiKey(): Attribute {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : (LlmKeyCrypto::decrypt($value) ?? $value),
            set: fn (?string $value) => $value === null ? null : LlmKeyCrypto::encrypt($value),
        );
    }
}
