@php
    $rows = $this->getMarginRows();
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>

        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start text-gray-500 border-b">
                            <th class="py-2 pe-4 text-start">{{ __('common.name') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('panel.profit_margin_revenue_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('panel.profit_margin_cost_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('panel.profit_margin_margin_col') }}</th>
                            <th class="py-2 text-start">{{ __('panel.profit_margin_pct_col') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pe-4">{{ $row['tenant_name'] }}</td>
                                <td class="py-2 pe-4">{{ \App\Support\Money::toman((int) $row['revenue']) }}</td>
                                <td class="py-2 pe-4">{{ \App\Support\Money::toman((int) $row['cost']) }}</td>
                                <td class="py-2 pe-4 {{ $row['margin'] < 0 ? 'text-danger-600' : 'text-success-600' }}">
                                    {{ \App\Support\Money::toman((int) $row['margin']) }}
                                </td>
                                <td class="py-2">
                                    {{ $row['margin_pct'] === null ? __('panel.profit_margin_no_revenue') : \App\Support\Numbers::format($row['margin_pct'], 1) . '%' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
