<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('plans.margin_widget_heading') }}</x-slot>
        <x-slot name="description">{{ __('plans.margin_widget_description') }}</x-slot>

        @php $rows = $this->getRows(); @endphp

        @if (empty($rows))
            <p class="text-sm text-gray-500">{{ __('plans.margin_widget_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start text-xs text-gray-500">
                            <th class="py-2 pe-4">{{ __('plans.margin_col_plan') }}</th>
                            <th class="py-2 pe-4">{{ __('plans.margin_col_price') }}</th>
                            <th class="py-2 pe-4">{{ __('plans.margin_col_quota') }}</th>
                            <th class="py-2 pe-4">{{ __('plans.margin_col_model') }}</th>
                            <th class="py-2 pe-4">{{ __('plans.margin_col_cost') }}</th>
                            <th class="py-2 pe-4">{{ __('plans.margin_col_margin') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-2 pe-4 font-medium">{{ $row['name'] }}</td>
                                <td class="py-2 pe-4">{{ $this->formatMoney($row['price_monthly']) }}</td>
                                <td class="py-2 pe-4">{{ number_format($row['max_tokens']) }}</td>
                                <td class="py-2 pe-4 text-xs text-gray-500">{{ $row['model_tier'] }}</td>
                                <td class="py-2 pe-4">
                                    @if ($row['has_price_data'])
                                        {{ $this->formatMoney($row['estimated_cost']) }}
                                    @else
                                        <span class="text-gray-400">{{ __('plans.margin_no_price_data') }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pe-4">
                                    @if ($row['is_negative'])
                                        <span class="inline-flex items-center gap-1 rounded-full bg-danger-50 px-2 py-0.5 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                                            {{ $this->formatMoney($row['margin_toman']) }}
                                            @if ($row['margin_percent'] !== null)
                                                ({{ number_format($row['margin_percent'], 0) }}%)
                                            @endif
                                        </span>
                                    @else
                                        {{ $this->formatMoney($row['margin_toman']) }}
                                        @if ($row['margin_percent'] !== null)
                                            ({{ number_format($row['margin_percent'], 0) }}%)
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
