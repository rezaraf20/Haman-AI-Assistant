<?php namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};

class Faq extends Model {
    use HasUuid, HasTenant;
    protected $fillable = ['chatbot_id','question','answer','category','source','language','is_active','sort_order'];
    protected $casts = ['is_active'=>'boolean'];
    public function chatbot() { return $this->belongsTo(Chatbot::class); }
    public function scopeActive($q) { return $q->where('is_active',true); }
}
