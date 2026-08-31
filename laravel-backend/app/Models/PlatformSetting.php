<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class PlatformSetting extends Model {
    use HasUuid;
    protected $fillable = [
        'zarinpal_merchant_id', 'zarinpal_sandbox',
        'melipayamak_username', 'melipayamak_password', 'melipayamak_sender',
        'melipayamak_use_pattern', 'melipayamak_pattern_id',
    ];
    protected $casts = ['zarinpal_sandbox' => 'boolean', 'melipayamak_use_pattern' => 'boolean'];

    // Always exactly one row — created by migration, never deleted. Callers
    // just need "the" settings, not a specific id.
    public static function current(): self {
        return static::first() ?? static::create([]);
    }
}
