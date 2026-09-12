<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class OrderStatusOtp extends Model {
    use HasUuid;

    protected $fillable = [
        'chatbot_id', 'tenant_id', 'conversation_id', 'contact', 'contact_type',
        'code_hash', 'attempts', 'ip', 'expires_at', 'consumed_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /**
     * Iranian mobile numbers arrive in several shapes (+98..., 0098...,
     * 9..., 09...). Everything stored and every cap counted uses this one
     * form, so retyping the same number differently can neither dodge the
     * hourly cap nor orphan a code that was already sent.
     * Returns null for anything that isn't a plausible Iranian mobile —
     * callers must treat that as "do not send".
     */
    public static function normalizePhone(string $phone): ?string {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 10) return null;
        $core = substr($digits, -10);
        if ($core[0] !== '9') return null;
        return '0' . $core;
    }
}
