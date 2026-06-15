<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
class Subscription extends Model {
    use HasUuid;
    protected $fillable=['tenant_id','plan_id','stripe_subscription_id','status','billing_cycle','current_period_start','current_period_end','cancel_at_period_end','cancelled_at','trial_end','metadata'];
    protected $casts=['current_period_start'=>'datetime','current_period_end'=>'datetime','cancelled_at'=>'datetime','trial_end'=>'datetime','cancel_at_period_end'=>'boolean','metadata'=>'array'];
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function plan()   { return $this->belongsTo(Plan::class); }
}
