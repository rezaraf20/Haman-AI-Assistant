@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('dashboard.admin_table_never_connected') }}</x-slot>
        <x-slot name="description">{{ __('dashboard.admin_table_never_connected_desc') }}</x-slot>

        @if (empty($rows))
            <p class="text-sm text-gray-500">{{ __('dashboard.admin_table_never_connected_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start text-gray-500 border-b">
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_never_connected_tenant_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_never_connected_domain_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_never_connected_created_col') }}</th>
                            <th class="py-2 text-start">{{ __('dashboard.admin_table_never_connected_silence_col') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pe-4">{{ $row['tenant'] }}</td>
                                <td class="py-2 pe-4 font-mono text-xs">{{ $row['domain'] }}</td>
                                <td class="py-2 pe-4 text-gray-500">{{ $this->formatCreatedAt($row['created_at']) }}</td>
                                <td class="py-2 font-medium text-danger-600">
                                    {{ __('dashboard.admin_table_never_connected_days', ['days' => $row['days_silent']]) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
