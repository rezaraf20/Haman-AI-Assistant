<?php namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};

class Document extends Model {
    use HasUuid, HasTenant;
    protected $fillable = ['chatbot_id','source_type','source_url','external_id','title','raw_content','content_hash','language','metadata','status','chunk_count','error_message','retry_count','last_synced_at','indexed_at'];
    protected $casts = ['metadata'=>'array','last_synced_at'=>'datetime','indexed_at'=>'datetime'];
    public function chatbot() { return $this->belongsTo(Chatbot::class); }
    public function chunks()  { return $this->hasMany(Chunk::class); }
    public function scopePending($q) { return $q->whereIn('status',['pending','outdated']); }
}
