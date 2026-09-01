<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\TenantService;
class ResetMonthlyUsageJob implements ShouldQueue {
    use Dispatchable, Queueable;
    public function handle(TenantService $svc): void { $svc->resetMonthlyUsage(); }
}
