<?php
namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    use HasUuid;

    protected $fillable = [
        'kind', 'status', 'filename', 'bytes', 'destination', 'remote_path',
        'error', 'duration_ms', 'verified_at', 'verified_counts', 'verify_error',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'verified_counts' => 'array',
        'verified_at'     => 'datetime',
        'started_at'      => 'datetime',
        'finished_at'     => 'datetime',
        'bytes'           => 'integer',
        'duration_ms'     => 'integer',
    ];

    public function scopeSucceeded($query) { return $query->where('status', 'success'); }

    /** The most recent attempt of any kind — a failure is news too. */
    public static function latestAttempt(): ?self
    {
        return static::orderByDesc('started_at')->first();
    }

    public static function latestSuccess(): ?self
    {
        return static::succeeded()->orderByDesc('started_at')->first();
    }

    /** The most recent backup that was actually restored and checked. */
    public static function latestVerified(): ?self
    {
        return static::whereNotNull('verified_at')->orderByDesc('verified_at')->first();
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->bytes;
        if ($bytes <= 0) return '—';

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) return round($bytes, 1) . ' ' . $unit;
            $bytes /= 1024;
        }
        return round($bytes, 1) . ' TB';
    }
}
