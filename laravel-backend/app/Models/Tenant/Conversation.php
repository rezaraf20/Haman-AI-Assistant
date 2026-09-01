<?php namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};

class Conversation extends Model {
    use HasUuid, HasTenant;
    const UPDATED_AT = null;
    protected $fillable = ['chatbot_id','session_id','visitor_id','status','language','page_url','referrer','ip_country','device_type','browser','message_count','total_tokens','is_converted','ended_at','started_at'];
    protected $casts = ['is_converted'=>'boolean','started_at'=>'datetime','ended_at'=>'datetime'];
    public function chatbot()  { return $this->belongsTo(Chatbot::class); }
    public function messages() { return $this->hasMany(Message::class)->orderBy('created_at'); }
    public function isActive(): bool { return $this->status === 'active'; }
}
