<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

/** See SignupRisk::blockReason()/recordBlock() and the migration's own docblock. */
class SignupBlock extends Model {
    use HasUuid;
    public $timestamps = false;
    protected $fillable = ['reason', 'source', 'ip', 'user_agent', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
