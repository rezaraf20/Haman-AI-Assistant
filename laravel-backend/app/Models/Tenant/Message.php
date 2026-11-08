<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Message extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    // The id is a UUID (set in creating() below, and the column's own
    // default). Without these two, Eloquent treated it as an auto-increment
    // integer: after create() it overwrote the UUID with the insert result
    // cast to int — "9" for a UUID starting with 9 — and that number is what
    // the chat endpoint returned to the widget as message_id, so the widget's
    // feedback call (which requires a UUID) could never succeed.
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'retrieved_chunk_ids' => 'array',
        'retrieval_scores'    => 'array',
        'is_fallback'         => 'boolean',
        'is_unanswered'       => 'boolean',
        'prompt_tokens'       => 'integer',
        'completion_tokens'   => 'integer',
        'total_tokens'        => 'integer',
        'cost_toman'          => 'float',
        'latency_ms'          => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            if (empty($m->id)) {
                $m->id = Str::uuid()->toString();
            }
            if (empty($m->retrieved_chunk_ids)) {
                $m->retrieved_chunk_ids = [];
            }
            if (empty($m->retrieval_scores)) {
                $m->retrieval_scores = [];
            }
            if (empty($m->created_at)) {
                $m->created_at = now();
            }
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}