<x-filament-panels::page>
    {{-- Filters --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid gap-4 p-4 md:grid-cols-5">
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('activity_log.filter_user') }}</label>
                <select wire:model.live="userFilter"
                        class="w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-gray-700">
                    <option value="">{{ __('activity_log.all') }}</option>
                    @foreach ($this->userOptions() as $id => $email)
                        <option value="{{ $id }}">{{ $email }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('activity_log.filter_tenant') }}</label>
                <select wire:model.live="tenantFilter"
                        class="w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-gray-700">
                    <option value="">{{ __('activity_log.all') }}</option>
                    @foreach ($this->tenantOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('activity_log.filter_action') }}</label>
                <select wire:model.live="actionFilter"
                        class="w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-gray-700">
                    <option value="">{{ __('activity_log.all') }}</option>
                    @foreach ($this->actionOptions() as $group => $actions)
                        <optgroup label="{{ $group }}">
                            @foreach ($actions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('activity_log.filter_from') }}</label>
                <input type="date" wire:model.live="fromFilter"
                       class="w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-gray-700">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('activity_log.filter_to') }}</label>
                <input type="date" wire:model.live="toFilter"
                       class="w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-gray-700">
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
            <span class="text-sm text-gray-500">
                {{ __('activity_log.total', ['count' => number_format($this->getTotal())]) }}
            </span>
            <div class="flex gap-2">
                <x-filament::button color="gray" size="sm" wire:click="resetFilters">
                    {{ __('activity_log.reset_filters') }}
                </x-filament::button>
                <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-down-tray" wire:click="exportCsv">
                    {{ __('activity_log.export_csv') }}
                </x-filament::button>
            </div>
        </div>
    </div>

    {{-- This page offers no delete or bulk action anywhere, by design.
         The log is append-only; see App\Support\PlatformActivity. --}}
    <div class="mt-4 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-100 text-start dark:border-gray-800">
                <tr class="text-gray-500">
                    <th class="p-3 text-start font-medium">{{ __('activity_log.col_when') }}</th>
                    <th class="p-3 text-start font-medium">{{ __('activity_log.col_who') }}</th>
                    <th class="p-3 text-start font-medium">{{ __('activity_log.col_action') }}</th>
                    <th class="p-3 text-start font-medium">{{ __('activity_log.col_tenant') }}</th>
                    <th class="p-3 text-start font-medium">{{ __('activity_log.col_subject') }}</th>
                    <th class="p-3 text-start font-medium">{{ __('activity_log.col_reason') }}</th>
                    <th class="p-3 text-start font-medium">{{ __('activity_log.col_ip') }}</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->getRows() as $row)
                    <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                        <td class="p-3 whitespace-nowrap">{{ \App\Support\Jalali::dateTime($row->created_at) }}</td>
                        <td class="p-3">
                            <div>{{ $row->user_email ?? '—' }}</div>
                            @if ($row->platform_role)
                                <div class="text-xs text-gray-500">{{ __('platform_users.role_' . $row->platform_role) }}</div>
                            @endif
                        </td>
                        <td class="p-3">{{ $this->actionLabel($row->action) }}</td>
                        <td class="p-3">{{ $row->tenant_name ?? '—' }}</td>
                        <td class="p-3">
                            @if ($row->subject_type)
                                <div>{{ __('activity_log.subject_' . $row->subject_type) }}</div>
                                <div class="text-xs text-gray-400 font-mono">{{ \Illuminate\Support\Str::limit($row->subject_id, 12) }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td class="p-3">{{ $this->reasonLabel($row->reason) ?? '—' }}</td>
                        <td class="p-3 font-mono text-xs">{{ $row->ip ?? '—' }}</td>
                        <td class="p-3 text-end">
                            @if ($row->changed_keys)
                                <button type="button" wire:click="toggleRow('{{ $row->id }}')"
                                        class="text-xs text-primary-600 hover:underline">
                                    {{ $this->isExpanded($row->id) ? __('activity_log.hide_diff') : __('activity_log.show_diff') }}
                                </button>
                            @endif
                        </td>
                    </tr>

                    @if ($this->isExpanded($row->id) && $row->changed_keys)
                        <tr class="bg-gray-50 dark:bg-gray-800/40">
                            <td colspan="8" class="p-3">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="text-gray-500">
                                            <th class="p-2 text-start font-medium">{{ __('activity_log.diff_field') }}</th>
                                            <th class="p-2 text-start font-medium">{{ __('activity_log.diff_before') }}</th>
                                            <th class="p-2 text-start font-medium">{{ __('activity_log.diff_after') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($row->changed_keys as $key)
                                            @php
                                                $was = $row->before_data[$key] ?? null;
                                                $now = $row->after_data[$key] ?? null;
                                                $fmt = fn ($v) => is_array($v)
                                                    ? json_encode($v, JSON_UNESCAPED_UNICODE)
                                                    : (is_bool($v) ? ($v ? '✓' : '✗') : (string) ($v ?? '—'));
                                            @endphp
                                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                                <td class="p-2 font-medium">{{ $key }}</td>
                                                <td class="p-2 font-mono text-danger-600 dark:text-danger-400">{{ $fmt($was) }}</td>
                                                <td class="p-2 font-mono text-success-700 dark:text-success-400">{{ $fmt($now) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @if ($row->user_agent)
                                    <div class="mt-2 text-xs text-gray-400">{{ $row->user_agent }}</div>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="8" class="p-6 text-center text-gray-500">{{ __('activity_log.none') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->getLastPage() > 1)
        <div class="mt-4 flex items-center justify-center gap-2">
            <x-filament::button color="gray" size="sm" wire:click="goToPage({{ $page - 1 }})" :disabled="$page <= 1">
                {{ __('activity_log.previous') }}
            </x-filament::button>
            <span class="text-sm text-gray-500">
                {{ __('activity_log.page_of', ['page' => $page, 'total' => $this->getLastPage()]) }}
            </span>
            <x-filament::button color="gray" size="sm" wire:click="goToPage({{ $page + 1 }})" :disabled="$page >= $this->getLastPage()">
                {{ __('activity_log.next') }}
            </x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
