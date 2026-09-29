@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('dashboard.admin_table_trial_tenants') }}</x-slot>

        @if (empty($rows))
            <p class="text-sm text-gray-500">{{ __('dashboard.admin_table_trial_tenants_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start text-gray-500 border-b">
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_trial_tenants_tenant_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_trial_tenants_messages_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_trial_tenants_tokens_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_trial_tenants_cost_col') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('dashboard.admin_table_trial_tenants_since_col') }}</th>
                            <th class="py-2 text-start">{{ __('dashboard.admin_table_trial_tenants_action_col') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b last:border-0" wire:key="trial-tenant-{{ $row['chatbot_id'] }}">
                                <td class="py-2 pe-4">{{ $row['tenant_name'] }}</td>
                                <td class="py-2 pe-4">{{ $row['message_count'] }}</td>
                                <td class="py-2 pe-4">{{ $row['tokens'] }}</td>
                                <td class="py-2 pe-4">{{ $this->formatMoney($row['cost_toman']) }}</td>
                                <td class="py-2 pe-4 text-gray-500">{{ $this->formatWhen($row['created_at']) }}</td>
                                <td class="py-2">
                                    <button
                                        type="button"
                                        wire:click="deactivate('{{ $row['chatbot_id'] }}')"
                                        wire:confirm="{{ __('dashboard.admin_table_trial_tenants_deactivate_confirm') }}"
                                        class="text-danger-600 text-sm hover:underline"
                                    >{{ __('dashboard.admin_table_trial_tenants_deactivate') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
