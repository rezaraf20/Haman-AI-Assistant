<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};
class AnalyticsDaily extends Model {
    use HasUuid,HasTenant;
    // Eloquent's default table-name inference pluralizes "AnalyticsDaily" as
    // "analytics_dailies" (standard English y->ies pluralization on "daily")
    // — the real table is analytics_daily (see TenantService::
    // createTenantTables). Invisible until now because the table itself
    // never existed before this task, so nothing had ever successfully
    // queried through this model.
    protected $table='analytics_daily';
    protected $fillable=['chatbot_id','date','total_conversations','total_messages','user_messages','assistant_messages','total_tokens','prompt_tokens','completion_tokens','cost_toman','unique_visitors','avg_messages_per_conv','avg_response_latency_ms','fallback_count','unanswered_count','escalation_count','positive_feedback','negative_feedback','products_recommended','conversions'];
    protected $casts=['date'=>'date','avg_messages_per_conv'=>'float','cost_toman'=>'float'];
    public function chatbot() { return $this->belongsTo(Chatbot::class); }
}
