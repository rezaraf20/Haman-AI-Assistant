<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Traits\HasUuid;

class ApiKey extends Model {
    use HasUuid;
    protected $fillable = ['tenant_id','chatbot_id','created_by','name','key_prefix','key_hash','scopes','last_used_at','last_used_ip','expires_at','is_active'];
    protected $hidden = ['key_hash'];
    protected $casts = ['scopes'=>'array','is_active'=>'boolean','last_used_at'=>'datetime','expires_at'=>'datetime'];
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function chatbotIndexEntry() { return $this->belongsTo(ChatbotIndexEntry::class, 'chatbot_id', 'chatbot_id'); }
    public function isExpired(): bool { return $this->expires_at && $this->expires_at->isPast(); }
    public function scopeActive($q) { return $q->where('is_active', true); }

    /**
     * Single source of truth for key generation — used by both the tenant
     * self-service endpoint (TenantController::createApiKey) and the admin
     * panel's ApiKeyResource, so every key produced anywhere is compatible
     * with AuthenticateTenantApiKey's hfp_ prefix + bcrypt verification.
     * @return array{0: ApiKey, 1: string} [model, plaintext key — shown once]
     */
    public static function generate(string $tenantId, ?string $chatbotId, string $name, ?string $createdBy = null, ?Carbon $expiresAt = null): array {
        $raw = 'hfp_'.Str::random(32);
        $key = static::create([
            'tenant_id'  => $tenantId,
            'chatbot_id' => $chatbotId,
            'created_by' => $createdBy,
            'name'       => $name,
            'key_prefix' => substr($raw, 0, 12),
            'key_hash'   => password_hash($raw, PASSWORD_BCRYPT, ['cost' => 12]),
            'scopes'     => ['read','write','sync','chat'],
            'expires_at' => $expiresAt,
        ]);
        return [$key, $raw];
    }
}
