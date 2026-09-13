{{-- Live probes, each wrapped separately so one dead dependency reports as
     dead rather than blanking the whole panel. --}}
<div class="grid gap-3 sm:grid-cols-2">
    @foreach ($checks as $name => $check)
        <div class="flex items-start gap-3 rounded-lg border p-3
                    {{ $check['ok'] ? 'border-success-200 bg-success-50 dark:bg-success-950/20 dark:border-success-900'
                                    : 'border-danger-200 bg-danger-50 dark:bg-danger-950/20 dark:border-danger-900' }}">
            <x-filament::icon
                :icon="$check['ok'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'"
                @class(['h-5 w-5 shrink-0', 'text-success-600' => $check['ok'], 'text-danger-600' => !$check['ok']])
            />
            <div class="min-w-0">
                <div class="font-medium">{{ __('settings.health_' . $name) }}</div>
                <div class="text-xs text-gray-600 dark:text-gray-400 break-words">{{ $check['message'] }}</div>
            </div>
        </div>
    @endforeach
</div>
