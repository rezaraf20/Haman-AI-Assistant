<?php
namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A bill for one period of something renewable — a paid plan or a paid
 * chatbot. Issued ahead of the period, charged from the wallet when the
 * period starts (App\Services\BillingService). amount is in the minor unit
 * of currency (whole Toman for IRT).
 *
 * Status only ever moves open → paid or open → void; a paid or void
 * invoice is a record, not something to edit.
 */
class Invoice extends Model
{
    use HasUuid;

    public const PLAN = 'plan_renewal';
    public const CHATBOT = 'chatbot_renewal';

    protected $fillable = [
        'tenant_id', 'purpose', 'subject_id', 'plan_id', 'description', 'currency', 'amount',
        'period_start', 'period_end', 'status', 'issued_at', 'due_at', 'paid_at', 'voided_at',
        'void_reason', 'wallet_transaction_id', 'attempts', 'last_attempt_at', 'last_failure',
    ];

    protected $casts = [
        'amount' => 'integer', 'number' => 'integer', 'attempts' => 'integer',
        'period_start' => 'datetime', 'period_end' => 'datetime', 'issued_at' => 'datetime',
        'due_at' => 'datetime', 'paid_at' => 'datetime', 'voided_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function plan() { return $this->belongsTo(Plan::class); }

    public function isOpen(): bool { return $this->status === 'open'; }
}
