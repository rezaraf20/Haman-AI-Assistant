<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Plan extends Model {
    use HasUuid;
    protected $fillable = ['name','slug','description','price_monthly','price_yearly','max_chatbots','max_tokens_monthly','max_documents','max_messages_monthly','max_domains','model_tier','features','is_active','sort_order'];
    protected $casts = ['features'=>'array','is_active'=>'boolean','price_monthly'=>'float'];
    public function tenants() { return $this->hasMany(Tenant::class); }
    public function scopeActive($q) { return $q->where('is_active',true)->orderBy('sort_order'); }
}
