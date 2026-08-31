<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class Ticket extends Model {
    use HasUuid;
    protected $fillable = ['tenant_id', 'chatbot_id', 'subject', 'status', 'priority'];
    public function tenant()   { return $this->belongsTo(Tenant::class); }
    public function messages() { return $this->hasMany(TicketMessage::class)->orderBy('created_at'); }
}
