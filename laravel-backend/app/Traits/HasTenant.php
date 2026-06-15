<?php namespace App\Traits;
trait HasTenant {
    public function getConnectionName(): string { return 'pgsql'; }
}
