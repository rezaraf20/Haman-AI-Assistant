@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('dashboard.admin_table_tenants_at_risk') }}</x-slot>

        @if (empty($rows))
            <p class="text-sm text-gray-500">{{ __('dashboard.admin_table_tenants_at_risk_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start text-gray-500 border-b">
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_tenants_at_risk_tenant_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_tenants_at_risk_reason_col') }}</th>
                            <th class="py-2 text-start">{{ __('dashboard.admin_table_tenants_at_risk_value_col') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pe-4">{{ $row['tenant'] }}</td>
                                <td class="py-2 pe-4">{{ $row['reason'] }}</td>
                                <td class="py-2 font-medium">{{ $row['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
