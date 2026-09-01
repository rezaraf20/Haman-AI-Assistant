<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Message extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'retrieved_chunk_ids' => 'array',
        'retrieval_scores'    => 'array',
        'is_fallback'         => 'boolean',
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