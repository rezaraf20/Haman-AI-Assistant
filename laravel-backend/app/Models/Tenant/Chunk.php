<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};
class Chunk extends Model {
    use HasUuid,HasTenant;
    public $timestamps=false;
    protected $fillable=['document_id','chatbot_id','chunk_index','content','embedding','metadata','token_count','embedding_model','created_at'];
    protected $casts=['metadata'=>'array','token_count'=>'integer','created_at'=>'datetime'];
    public function document() { return $this->belongsTo(Document::class); }
}
