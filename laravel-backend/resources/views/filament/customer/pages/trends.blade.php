@php
    $data = $this->getData();
    $hasData = $data['has_enough_data'];
@endphp
<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Range selector + exports --}}
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-500">{{ __('trends.range_label') }}:</span>
                    @foreach ($this->getRangeOptions() as $key => $label)
                        <button type="button" wire:click="setRange('{{ $key }}')"
                                @class([
                                    'px-3 py-1.5 rounded-lg text-sm border transition',
                                    'bg-primary-600 text-white border-primary-600' => $this->range === $key,
                                    'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' => $this->range !== $key,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>
                <div class="flex items-center gap-2">
                    <x-filament::button wire:click="downloadCsv" color="gray" size="sm">
                        {{ __('trends.download_csv') }}
                    </x-filament::button>
                    <x-filament::button tag="a" size="sm" color="gray"
                        href="{{ route('portal.trends.print', ['range' => $this->range]) }}" target="_blank">
                        {{ __('trends.print_pdf') }}
                    </x-filament::button>
                </div>
            </div>
            <div class="mt-3 text-sm text-gray-500">
                <span dir="ltr">{{ __('trends.range_summary', ['from' => $data['range_start'], 'to' => $data['range_end']]) }}</span>
                &nbsp;·&nbsp;
                <span dir="ltr">{{ __('trends.compared_to', ['from' => $data['prev_start'], 'to' => $data['prev_end']]) }}</span>
            </div>
            <div class="mt-1 text-sm">
                {{ __('trends.questions_total') }}:
                <strong>{{ number_format($data['user_messages']) }}</strong>
                @if ($data['messages_change'] !== null)
                    <span @class(['text-success-600' => $data['messages_change'] >= 0, 'text-danger-600' => $data['messages_change'] < 0])>
                        ({{ $this->changeText($data['messages_change']) }})
                    </span>
                @endif
            </div>
        </x-filament::section>

        {{-- The low-data state is a first-class answer here, not a fallback:
             a chart drawn from six conversations is worse than honest silence. --}}
        @if (! $hasData)
            <x-filament::section>
                <div class="text-center py-6">
                    <h3 class="text-base font-semibold text-gray-700">{{ __('trends.low_data_title') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 max-w-xl mx-auto">
                        {{ __('trends.low_data_body', ['count' => number_format($data['user_messages']), 'min' => $data['min_messages']]) }}
                    </p>
                </div>
            </x-filament::section>
        @else

            @include('filament.customer.pages.partials.trends-body', ['data' => $data, 'page' => $this])

        @endif

        {{-- Seasonality states its own requirement whether or not the rest
             of the report has data. --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('trends.seasonal') }}</x-slot>
            <x-slot name="description">{{ __('trends.seasonal_desc') }}</x-slot>
            @if (! $data['seasonal']['available'])
                <p class="text-sm text-gray-500">{{ __('trends.seasonal_unavailable') }}</p>
                <p class="mt-1 text-xs text-gray-400">
                    {{ __('trends.seasonal_progress', ['days' => number_format($data['history_days'])]) }}
                </p>
            @else
                <div class="flex flex-wrap gap-8">
                    <div>
                        <div class="text-xs text-gray-500">{{ __('trends.seasonal_this_month') }}</div>
                        <div class="text-2xl font-semibold">{{ number_format($data['seasonal']['current']) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">{{ __('trends.seasonal_last_year') }}</div>
                        <div class="text-2xl font-semibold">{{ number_format($data['seasonal']['last_year']) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">{{ __('trends.col_change') }}</div>
                        <div @class([
                            'text-2xl font-semibold',
                            'text-success-600' => ($data['seasonal']['change_pct'] ?? 0) >= 0,
                            'text-danger-600' => ($data['seasonal']['change_pct'] ?? 0) < 0,
                        ])>{{ $this->changeText($data['seasonal']['change_pct']) }}</div>
                    </div>
                </div>
            @endif
        </x-filament::section>

        <p class="text-xs text-gray-400">{{ __('trends.generated_at', ['time' => \App\Support\Jalali::dateTime($data['generated_at'])]) }}</p>
    </div>
</x-filament-panels::page>
