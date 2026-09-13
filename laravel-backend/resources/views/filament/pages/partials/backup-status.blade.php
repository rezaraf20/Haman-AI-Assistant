@php
    // Three separate facts, because they fail separately: whether a backup
    // ran, whether it left this server, and whether anyone has proved it can
    // be restored. A green tick on the first two means nothing without the
    // third.
    $staleAfterHours = 36;
    $isStale = !$success || $success->started_at->lt(now()->subHours($staleAfterHours));
    $verifyStale = !$verified || $verified->verified_at->lt(now()->subDays(8));
@endphp

<div class="space-y-3">
    {{-- Last run --}}
    <div class="flex items-start gap-3 rounded-lg border p-3
        {{ $latest && $latest->status === 'failed'
             ? 'border-danger-200 bg-danger-50 dark:bg-danger-950/20 dark:border-danger-900'
             : ($isStale ? 'border-warning-200 bg-warning-50 dark:bg-warning-950/20 dark:border-warning-900'
                         : 'border-success-200 bg-success-50 dark:bg-success-950/20 dark:border-success-900') }}">
        <x-filament::icon
            :icon="$latest && $latest->status === 'failed' ? 'heroicon-o-x-circle' : ($isStale ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')"
            @class(['h-5 w-5 shrink-0',
                    'text-danger-600' => $latest && $latest->status === 'failed',
                    'text-warning-600' => $isStale && !($latest && $latest->status === 'failed'),
                    'text-success-600' => !$isStale && !($latest && $latest->status === 'failed')])
        />
        <div class="min-w-0">
            <div class="font-medium">{{ __('settings.backup_last_run') }}</div>
            @if (!$latest)
                <div class="text-sm text-danger-700 dark:text-danger-400">{{ __('settings.backup_never_run') }}</div>
            @else
                <div class="text-sm">
                    {{ \App\Support\Jalali::dateTime($latest->started_at) }}
                    — <span class="font-medium">{{ __('settings.backup_status_' . $latest->status) }}</span>
                    @if ($latest->status === 'success')
                        · {{ $latest->humanSize() }}
                        · {{ number_format($latest->duration_ms / 1000, 1) }}s
                    @endif
                </div>
                @if ($latest->error)
                    <div class="mt-1 text-xs text-danger-700 dark:text-danger-400 break-words">{{ $latest->error }}</div>
                @endif
                @if ($isStale && $latest->status !== 'failed')
                    <div class="mt-1 text-xs text-warning-700 dark:text-warning-400">
                        {{ __('settings.backup_stale', ['hours' => $staleAfterHours]) }}
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- Off-server copy --}}
    <div class="flex items-start gap-3 rounded-lg border p-3
        {{ $offsite ? 'border-success-200 bg-success-50 dark:bg-success-950/20 dark:border-success-900'
                    : 'border-danger-200 bg-danger-50 dark:bg-danger-950/20 dark:border-danger-900' }}">
        <x-filament::icon :icon="$offsite ? 'heroicon-o-cloud' : 'heroicon-o-exclamation-triangle'"
            @class(['h-5 w-5 shrink-0', 'text-success-600' => $offsite, 'text-danger-600' => !$offsite]) />
        <div class="min-w-0">
            <div class="font-medium">{{ __('settings.backup_offsite') }}</div>
            <div class="text-sm">
                @if ($offsite)
                    {{ __('settings.backup_offsite_on') }}
                    @if ($success?->remote_path)
                        <span class="font-mono text-xs text-gray-500">{{ $success->remote_path }}</span>
                    @endif
                @else
                    <span class="text-danger-700 dark:text-danger-400">{{ __('settings.backup_offsite_off') }}</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Proven restorable --}}
    <div class="flex items-start gap-3 rounded-lg border p-3
        {{ $verifyStale ? 'border-warning-200 bg-warning-50 dark:bg-warning-950/20 dark:border-warning-900'
                        : 'border-success-200 bg-success-50 dark:bg-success-950/20 dark:border-success-900' }}">
        <x-filament::icon :icon="$verifyStale ? 'heroicon-o-question-mark-circle' : 'heroicon-o-shield-check'"
            @class(['h-5 w-5 shrink-0', 'text-warning-600' => $verifyStale, 'text-success-600' => !$verifyStale]) />
        <div class="min-w-0">
            <div class="font-medium">{{ __('settings.backup_verified') }}</div>
            @if (!$verified)
                <div class="text-sm text-warning-700 dark:text-warning-400">{{ __('settings.backup_never_verified') }}</div>
            @else
                <div class="text-sm">
                    {{ \App\Support\Jalali::dateTime($verified->verified_at) }} — {{ $verified->filename }}
                </div>
                @if ($verified->verified_counts)
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                        @foreach ($verified->verified_counts as $table => $count)
                            <span><span class="font-mono">{{ $table }}</span>: {{ number_format($count) }}</span>
                        @endforeach
                    </div>
                @endif
            @endif
            @if ($success?->verify_error)
                <div class="mt-1 text-xs text-danger-700 dark:text-danger-400">{{ $success->verify_error }}</div>
            @endif
        </div>
    </div>
</div>
