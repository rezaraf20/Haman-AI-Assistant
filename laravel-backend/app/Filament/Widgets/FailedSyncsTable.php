<?php
namespace App\Filament\Widgets;

use App\Models\Tenant;
use App\Support\Jalali;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\{DB, Cache};

// sync_jobs lives per-tenant-schema (there's no cross-tenant sync log table),
// so this is inherently one query per tenant — acceptable at the platform's
// current scale (a handful of tenants) and cached for 5 minutes; if the
// tenant count grows enough for this to matter, this is the widget to
// revisit first (e.g. a public-schema failed_syncs rollup written
// alongside AggregateAnalyticsJob).
class FailedSyncsTable extends Widget {
    protected static string $view = 'filament.widgets.failed-syncs-table';
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = false;
    private const LIMIT = 10;

    public function getRows(): array {
        return Cache::remember('dashboard:admin:failed-syncs', 300, function () {
            $rows = [];
            foreach (Tenant::active()->get() as $tenant) {
                DB::statement("SET search_path TO {$tenant->schema_name}, public");
                $failed = DB::table('sync_jobs')
                    ->where('status', 'failed')
                    ->orderByDesc('created_at')
                    ->limit(self::LIMIT)
                    ->get(['job_type', 'error_log', 'created_at']);

                foreach ($failed as $job) {
                    $errors = json_decode($job->error_log ?? '[]', true) ?: [];
                    $firstError = $errors[0]['error'] ?? null;
                    $rows[] = [
                        'tenant'     => $tenant->name,
                        'type'       => $job->job_type,
                        'error'      => $firstError ? \Illuminate\Support\Str::limit($firstError, 80) : '—',
                        'created_at' => $job->created_at,
                    ];
                }
            }
            DB::statement('SET search_path TO public');

            usort($rows, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
            return array_slice($rows, 0, self::LIMIT);
        });
    }

    public function formatWhen($value): string {
        return Jalali::dateTime($value) ?? '—';
    }
}
