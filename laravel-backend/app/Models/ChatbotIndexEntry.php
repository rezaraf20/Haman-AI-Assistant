<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

/**
 * Eloquent model over the public-schema `chatbot_index` table — the one place a
 * chatbot's identity is visible without switching into its tenant's schema.
 * Used to back the admin panel's global chatbot list/suspend action.
 */
class ChatbotIndexEntry extends Model {
    use HasUuid;
    protected $table = 'chatbot_index';
    protected $primaryKey = 'chatbot_id';
    public $timestamps = false;
    protected $fillable = ['chatbot_id','tenant_id','schema_name','is_active','name','primary_domain','expires_at','monthly_price_toman','disabled_reason','connection_alert_first_sent_at','connection_alert_second_sent_at'];
    protected $casts = ['is_active'=>'boolean','expires_at'=>'datetime','created_at'=>'datetime','connection_alert_first_sent_at'=>'datetime','connection_alert_second_sent_at'=>'datetime'];
    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function isOverdue(): bool { return $this->expires_at && $this->expires_at->isPast(); }
}
