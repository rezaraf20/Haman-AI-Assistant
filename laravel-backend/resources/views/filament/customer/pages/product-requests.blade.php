@php
    $groups = $this->getGroups();
@endphp
<x-filament-panels::page>
    <div class="space-y-6">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-500">{{ __('common.status') }}:</span>
                @foreach ($this->statusOptions() as $value => $label)
                    <button type="button" wire:click="setStatusFilter('{{ $value }}')"
                        class="px-3 py-1.5 rounded-lg text-sm {{ $statusFilter === $value ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' }}"
                    >{{ $label }}</button>
                @endforeach
            </div>
            <x-filament::button wire:click="exportCsv" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">
                {{ __('requests.export_csv') }}
            </x-filament::button>
        </div>

        <p class="text-sm text-gray-500">{{ __('requests.no_auto_sms_note') }}</p>

        @foreach ([
            ['bucket' => 'waiting', 'heading' => __('requests.section_waiting'), 'desc' => __('requests.section_waiting_desc')],
            ['bucket' => 'missing', 'heading' => __('requests.section_missing'), 'desc' => __('requests.section_missing_desc')],
        ] as $section)
            <x-filament::section>
                <x-slot name="heading">{{ $section['heading'] }}</x-slot>
                <x-slot name="description">{{ $section['desc'] }}</x-slot>

                @if (empty($groups[$section['bucket']]))
                    <p class="text-sm text-gray-500">{{ __('requests.empty_section') }}</p>
                @else
                    <div class="space-y-4">
                        @foreach ($groups[$section['bucket']] as $g)
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center justify-center min-w-7 h-7 px-2 rounded-full bg-primary-100 text-primary-700 text-sm font-bold">
                                                {{ number_format($g['people']) }}
                                            </span>
                                            <span class="font-semibold text-gray-800 dark:text-gray-100">{{ $g['item'] }}</span>
                                            @php
                                                $badge = match ($g['status']) {
                                                    'fulfilled' => 'bg-success-100 text-success-700',
                                                    'rejected'  => 'bg-danger-100 text-danger-700',
                                                    default     => 'bg-warning-100 text-warning-700',
                                                };
                                            @endphp
                                            <span class="px-2 py-0.5 rounded text-xs {{ $badge }}">
                                                {{ $this->statusOptions()[$g['status']] ?? $g['status'] }}
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            {{ __('requests.first_request') }}:
                                            <span dir="ltr">{{ \App\Support\Jalali::dateTime($g['first']) }}</span>
                                            &nbsp;·&nbsp;
                                            {{ __('requests.last_request') }}:
                                            <span dir="ltr">{{ \App\Support\Jalali::dateTime($g['last']) }}</span>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        <x-filament::button size="xs" color="success"
                                            wire:click="markGroup('{{ $g['key'] }}', 'fulfilled')">
                                            {{ __('requests.mark_fulfilled') }}
                                        </x-filament::button>
                                        <x-filament::button size="xs" color="danger"
                                            wire:click="markGroup('{{ $g['key'] }}', 'rejected')">
                                            {{ __('requests.mark_rejected') }}
                                        </x-filament::button>
                                        @if ($g['status'] !== 'open')
                                            <x-filament::button size="xs" color="gray"
                                                wire:click="markGroup('{{ $g['key'] }}', 'open')">
                                                {{ __('requests.mark_open') }}
                                            </x-filament::button>
                                        @endif
                                    </div>
                                </div>

                                {{-- The contacts, with the conversation behind each
                                     one click away — a number with no context is
                                     an awkward phone call. --}}
                                <div class="mt-3">
                                    <div class="text-xs text-gray-500 mb-1">{{ __('requests.waiting_contacts') }}</div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($g['contacts'] as $c)
                                            <a href="{{ $this->conversationUrl($c['conversation_id']) }}" target="_blank"
                                               class="text-xs px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-primary-600 hover:bg-gray-50 dark:hover:bg-gray-800">
                                                <span dir="ltr">{{ $c['contact'] }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                    <details class="mt-2">
                                        <summary class="text-xs text-gray-500 cursor-pointer">{{ __('requests.copy_list') }}</summary>
                                        <textarea readonly rows="{{ min(count($g['contacts']), 8) }}" dir="ltr"
                                            class="mt-1 w-full text-xs font-mono p-2 rounded border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900"
                                            onclick="this.select()">{{ $this->contactBlock($g) }}</textarea>
                                    </details>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
