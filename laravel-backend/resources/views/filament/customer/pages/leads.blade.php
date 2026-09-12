@php
    $leads = $this->getLeads();
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-2">
                @foreach (['all' => __('leads.filter_all'), 'new' => __('leads.status_new'), 'contacted' => __('leads.status_contacted'), 'closed' => __('leads.status_closed')] as $value => $label)
                    <button
                        type="button"
                        wire:click="setStatusFilter('{{ $value }}')"
                        class="px-3 py-1.5 rounded-lg text-sm {{ $statusFilter === $value ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' }}"
                    >{{ $label }}</button>
                @endforeach
            </div>
            <x-filament::button wire:click="exportCsv" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">
                {{ __('leads.export_csv') }}
            </x-filament::button>
        </div>

        {{-- Lead type is its own axis: a shop chasing restock requests
             wants only those, regardless of contacted/closed status. --}}
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-gray-500">{{ __('leads.filter_type') }}:</span>
            @foreach ($this->typeOptions() as $value => $label)
                <button
                    type="button"
                    wire:click="setTypeFilter('{{ $value }}')"
                    class="px-3 py-1.5 rounded-lg text-sm {{ $typeFilter === $value ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' }}"
                >{{ $label }}</button>
            @endforeach
        </div>

        <x-filament::section>
            @if (empty($leads))
                <p class="text-sm text-gray-500">{{ __('leads.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-start text-gray-500 border-b">
                                <th class="py-2 pe-4 text-start">{{ __('leads.col_contact') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('leads.col_type') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('leads.col_requested_item') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('leads.col_question') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('common.status') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('common.created_at') }}</th>
                                <th class="py-2 text-start">{{ __('leads.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($leads as $lead)
                                <tr class="border-b last:border-0" wire:key="lead-{{ $lead->id }}">
                                    <td class="py-2 pe-4">
                                        <span dir="ltr" class="inline-block">{{ $lead->contact }}</span>
                                        <span class="text-xs text-gray-400">({{ $lead->contact_type === 'email' ? __('common.email') : __('panel.mobile_number') }})</span>
                                    </td>
                                    <td class="py-2 pe-4">
                                        @php
                                            $typeColor = match ($lead->type ?? 'unanswered') {
                                                'out_of_stock'   => 'bg-warning-100 text-warning-700',
                                                'not_in_catalog' => 'bg-danger-100 text-danger-700',
                                                'volunteered'    => 'bg-success-100 text-success-700',
                                                default          => 'bg-gray-100 text-gray-700',
                                            };
                                        @endphp
                                        <span class="px-2 py-0.5 rounded text-xs {{ $typeColor }}">
                                            {{ $this->typeLabel($lead->type ?? 'unanswered') }}
                                        </span>
                                    </td>
                                    <td class="py-2 pe-4 font-medium">{{ $lead->requested_item ?: '—' }}</td>
                                    <td class="py-2 pe-4 max-w-xs truncate" title="{{ $lead->question }}">{{ $lead->question }}</td>
                                    <td class="py-2 pe-4">
                                        @php
                                            $badgeColor = match ($lead->status) {
                                                'new' => 'bg-danger-100 text-danger-700',
                                                'contacted' => 'bg-warning-100 text-warning-700',
                                                default => 'bg-gray-100 text-gray-700',
                                            };
                                            $badgeLabel = match ($lead->status) {
                                                'new' => __('leads.status_new'),
                                                'contacted' => __('leads.status_contacted'),
                                                default => __('leads.status_closed'),
                                            };
                                        @endphp
                                        <span class="px-2 py-0.5 rounded text-xs {{ $badgeColor }}">{{ $badgeLabel }}</span>
                                    </td>
                                    <td class="py-2 pe-4 text-gray-500">{{ \App\Support\Jalali::dateTime($lead->created_at) }}</td>
                                    <td class="py-2">
                                        <div class="flex gap-2">
                                            <a href="{{ $this->conversationUrl($lead->conversation_id) }}" class="text-xs text-primary-600 hover:underline">{{ __('leads.view_conversation') }}</a>
                                            @if ($lead->status === 'new')
                                                <button type="button" wire:click="markContacted('{{ $lead->id }}')" class="text-xs text-warning-600 hover:underline">{{ __('leads.mark_contacted') }}</button>
                                            @endif
                                            @if ($lead->status !== 'closed')
                                                <button type="button" wire:click="markClosed('{{ $lead->id }}')" class="text-xs text-gray-500 hover:underline">{{ __('leads.mark_closed') }}</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
