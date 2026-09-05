<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Jobs\AggregateAnalyticsJob;
use Carbon\Carbon;

/**
 * One-off catch-up for analytics_daily / platform_daily_stats: both tables
 * (and hamman:aggregate-analytics's schedule entry) are new — without this,
 * the dashboards' 30-day/12-month charts would have no history at all until
 * the daily schedule had run for that many days. Runs AggregateAnalyticsJob
 * synchronously (not queued) for each of the last N days so the dashboards
 * have real data to render immediately.
 */
class BackfillAnalyticsCommand extends Command {
    protected $signature   = 'hamman:backfill-analytics {days=90 : How many days back to backfill, inclusive of yesterday}';
    protected $description = 'Backfill analytics_daily and platform_daily_stats for the last N days from existing messages/conversations';

    public function handle(): void {
        $days = (int) $this->argument('days');
        $this->info("Backfilling analytics for the last {$days} day(s)...");

        $bar = $this->output->createProgressBar($days);
        for ($i = 1; $i <= $days; $i++) {
            $date = Carbon::today()->subDays($i)->toDateString();
            (new AggregateAnalyticsJob($date))->handle();
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info('Backfill complete.');
    }
}
