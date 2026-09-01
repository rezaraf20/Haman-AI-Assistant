<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};
class AnalyticsDaily extends Model {
    use HasUuid,HasTenant;
    protected $fillable=['chatbot_id','date','total_conversations','total_messages','user_messages','assistant_messages','total_tokens','prompt_tokens','completion_tokens','unique_visitors','avg_messages_per_conv','avg_response_latency_ms','fallback_count','escalation_count','positive_feedback','negative_feedback','products_recommended','conversions'];
    protected $casts=['date'=>'date','avg_messages_per_conv'=>'float'];
    public function chatbot() { return $this->belongsTo(Chatbot::class); }
}
