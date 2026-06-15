<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};
class PromptTemplate extends Model {
    use HasUuid,HasTenant;
    public $timestamps=false;
    protected $fillable=['chatbot_id','version','name','system_prompt','variables','is_active','performance_score'];
    protected $casts=['variables'=>'array','is_active'=>'boolean','performance_score'=>'float','created_at'=>'datetime'];
    public function chatbot() { return $this->belongsTo(Chatbot::class); }
}
