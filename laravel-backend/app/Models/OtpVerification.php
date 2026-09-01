<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class OtpVerification extends Model {
    use HasUuid;
    protected $fillable = ['phone', 'code', 'attempts', 'expires_at', 'consumed_at'];
    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
