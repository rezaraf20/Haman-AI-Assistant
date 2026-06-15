<?php namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};

class SyncJob extends Model {
    use HasUuid, HasTenant;
    const UPDATED_AT = null;
    protected $fillable = ['chatbot_id','job_type','triggered_by','status','items_total','items_processed','items_failed','error_log','payload','result','retry_count','started_at','completed_at'];
    protected $casts = ['error_log'=>'array','payload'=>'array','result'=>'array','started_at'=>'datetime','completed_at'=>'datetime'];
    public function chatbot() { return $this->belongsTo(Chatbot::class); }
    public function progressPercent(): int { if($this->items_total===0) return 0; return (int)(($this->items_processed/$this->items_total)*100); }
}
