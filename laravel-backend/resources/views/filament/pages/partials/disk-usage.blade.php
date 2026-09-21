{{-- What haman:disk-report sees from inside this container -- see that
     command for why image/build-cache usage isn't included here. --}}
<div class="space-y-3">
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border p-3">
            <div class="text-xs text-gray-600 dark:text-gray-400">{{ __('settings.disk_usage_logs') }}</div>
            <div class="font-medium">{{ $report['logs_human'] }}</div>
        </div>
        <div class="rounded-lg border p-3">
            <div class="text-xs text-gray-600 dark:text-gray-400">{{ __('settings.disk_usage_backups') }}</div>
            <div class="font-medium">{{ $report['backups_human'] }}</div>
        </div>
        <div @class([
            'rounded-lg border p-3',
            'border-danger-200 bg-danger-50 dark:bg-danger-950/20 dark:border-danger-900' => $report['warning'],
        ])>
            <div class="text-xs text-gray-600 dark:text-gray-400">{{ __('settings.disk_usage_filesystem') }}</div>
            <div class="font-medium">{{ $report['disk_used_human'] }} / {{ $report['disk_total_human'] }} ({{ $report['disk_percent'] }}%)</div>
        </div>
    </div>

    @if ($report['warning'])
        <div class="flex items-start gap-3 rounded-lg border border-danger-200 bg-danger-50 p-3 dark:bg-danger-950/20 dark:border-danger-900">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-danger-600" />
            <div class="text-sm">{{ __('settings.disk_usage_warning', ['percent' => $report['disk_percent'], 'threshold' => $report['warn_percent']]) }}</div>
        </div>
    @endif
</div>
