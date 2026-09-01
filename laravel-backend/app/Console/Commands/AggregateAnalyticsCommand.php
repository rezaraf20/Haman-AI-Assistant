<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Jobs\AggregateAnalyticsJob;
class AggregateAnalyticsCommand extends Command {
    protected $signature   = 'hamman:aggregate-analytics';
    protected $description = 'Aggregate analytics events into daily summary';
    public function handle(): void {
        AggregateAnalyticsJob::dispatch();
        $this->info('Analytics aggregation job dispatched');
    }
}
