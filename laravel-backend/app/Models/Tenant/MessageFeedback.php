<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};
class MessageFeedback extends Model {
    use HasUuid,HasTenant;
    public $timestamps=false;
    protected $fillable=['message_id','chatbot_id','rating','comment','created_at'];
    protected $casts=['rating'=>'integer','created_at'=>'datetime'];
    public function message() { return $this->belongsTo(Message::class); }
}
