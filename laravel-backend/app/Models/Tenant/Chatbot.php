<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid, HasTenant};

class Chatbot extends Model {
    use HasUuid, HasTenant;
    protected $fillable = ['name','type','status','system_prompt','welcome_message','fallback_response','embedding_model','llm_model','temperature','max_tokens_response','retrieval_top_k','retrieval_threshold','reranker_enabled','memory_window','widget_config','language','response_language','is_active'];
    protected $casts = ['widget_config'=>'array','is_active'=>'boolean','reranker_enabled'=>'boolean','temperature'=>'float','retrieval_threshold'=>'float'];
    public function domains()       { return $this->hasMany(ChatbotDomain::class); }
    public function documents()     { return $this->hasMany(Document::class); }
    public function conversations() { return $this->hasMany(Conversation::class); }
    public function syncJobs()      { return $this->hasMany(SyncJob::class); }
    public function products()      { return $this->hasMany(Product::class); }
    public function faqs()          { return $this->hasMany(Faq::class); }
    public function scopeActive($q) { return $q->where('is_active', true); }
}
