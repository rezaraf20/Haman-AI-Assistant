<?php namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use App\Traits\{HasUuid,HasTenant};

class Product extends Model {
    use HasUuid, HasTenant;
    protected $fillable = ['chatbot_id','woo_product_id','name','slug','sku','type','status','description','short_description','price','regular_price','sale_price','currency','stock_status','stock_quantity','average_rating','review_count','permalink','featured_image','attributes','tags','embedding_status','synced_at'];
    protected $casts = ['attributes'=>'array','tags'=>'array','price'=>'float','average_rating'=>'float','synced_at'=>'datetime'];
    public function chatbot() { return $this->belongsTo(Chatbot::class); }
    public function scopeInStock($q) { return $q->where('stock_status','instock'); }
}
