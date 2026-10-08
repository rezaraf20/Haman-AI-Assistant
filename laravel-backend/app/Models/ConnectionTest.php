<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

/** See ConnectionTestController and the migration's own docblock for why this exists. */
class ConnectionTest extends Model {
    use HasUuid;
    public $timestamps = false;
    protected $fillable = ['tenant_id', 'chatbot_id', 'api_key_id', 'outcome', 'message', 'ip', 'user_agent', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
    public function tenant() { return $this->belongsTo(Tenant::class); }
}
