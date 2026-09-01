<?php namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};

class ChatbotDomain extends Model {
    use HasUuid, HasTenant;
    public $timestamps = false;
    protected $fillable = ['chatbot_id','domain','is_active','created_at'];
    protected $casts = ['is_active'=>'boolean','created_at'=>'datetime'];
    public function chatbot() { return $this->belongsTo(Chatbot::class); }
}
