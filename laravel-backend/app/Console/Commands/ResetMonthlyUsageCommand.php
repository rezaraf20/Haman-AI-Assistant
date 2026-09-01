<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Jobs\ResetMonthlyUsageJob;
class ResetMonthlyUsageCommand extends Command {
    protected $signature   = 'hamman:reset-usage';
    protected $description = 'Reset monthly token/message usage counters for all tenants';
    public function handle(): void {
        ResetMonthlyUsageJob::dispatch();
        $this->info('Monthly usage reset job dispatched');
    }
}
