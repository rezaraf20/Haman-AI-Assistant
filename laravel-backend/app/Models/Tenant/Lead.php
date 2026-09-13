<?php namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};

class Lead extends Model {
    use HasUuid, HasTenant;
    const UPDATED_AT = null;
    protected $fillable = ['conversation_id','chatbot_id','name','contact','contact_type','question','requested_item','requested_product_id','type','request_status','status'];
    public function chatbot()      { return $this->belongsTo(Chatbot::class); }
    public function conversation() { return $this->belongsTo(Conversation::class); }
}
