<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class TicketMessage extends Model {
    use HasUuid;
    protected $fillable = ['ticket_id', 'sender_type', 'sender_id', 'body'];
    public function ticket() { return $this->belongsTo(Ticket::class); }
}
