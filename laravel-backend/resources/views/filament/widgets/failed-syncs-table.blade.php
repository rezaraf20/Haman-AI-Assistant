@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('dashboard.admin_table_failed_syncs') }}</x-slot>

        @if (empty($rows))
            <p class="text-sm text-gray-500">{{ __('dashboard.admin_table_failed_syncs_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start text-gray-500 border-b">
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_failed_syncs_tenant_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_failed_syncs_type_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_failed_syncs_error_col') }}</th>
                            <th class="py-2 text-start">{{ __('dashboard.admin_table_failed_syncs_when_col') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pe-4">{{ $row['tenant'] }}</td>
                                <td class="py-2 pe-4">{{ $row['type'] }}</td>
                                <td class="py-2 pe-4 text-danger-600">{{ $row['error'] }}</td>
                                <td class="py-2 text-gray-500">{{ $this->formatWhen($row['created_at']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
