<?php namespace App\Traits;
use Illuminate\Support\Str;
trait HasUuid {
    protected static function bootHasUuid(): void {
        static::creating(function($m) {
            if (empty($m->{$m->getKeyName()})) {
                $m->{$m->getKeyName()} = Str::uuid()->toString();
            }
        });
    }
    public function getIncrementing(): bool { return false; }
    public function getKeyType(): string    { return 'string'; }
}
