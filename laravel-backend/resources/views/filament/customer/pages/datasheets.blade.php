@php
    $attachments = $this->getAttachments();
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            @if (empty($attachments))
                <p class="text-sm text-gray-500">{{ __('datasheets.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-start text-gray-500 border-b">
                                <th class="py-2 pe-4 text-start">{{ __('datasheets.col_file') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('datasheets.col_product') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('datasheets.col_status') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('datasheets.col_pages') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('datasheets.col_cost') }}</th>
                                <th class="py-2 text-start">{{ __('common.created_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($attachments as $doc)
                                <tr class="border-b last:border-0" wire:key="doc-{{ $doc['id'] }}">
                                    <td class="py-2 pe-4">{{ $doc['title'] }}</td>
                                    <td class="py-2 pe-4 text-gray-500">{{ $doc['product_name'] ?? '—' }}</td>
                                    <td class="py-2 pe-4">
                                        @php
                                            $badgeColor = match ($doc['status']) {
                                                'indexed' => 'bg-success-100 text-success-700',
                                                'skipped' => 'bg-warning-100 text-warning-700',
                                                'failed' => 'bg-danger-100 text-danger-700',
                                                'processing' => 'bg-info-100 text-info-700',
                                                default => 'bg-gray-100 text-gray-700',
                                            };
                                            $badgeLabel = match ($doc['status']) {
                                                'indexed' => __('datasheets.status_indexed'),
                                                'skipped' => __('datasheets.status_skipped'),
                                                'failed' => __('datasheets.status_failed'),
                                                'processing' => __('datasheets.status_processing'),
                                                default => __('datasheets.status_pending'),
                                            };
                                        @endphp
                                        <span class="px-2 py-0.5 rounded text-xs {{ $badgeColor }}">{{ $badgeLabel }}</span>
                                        @if ($doc['error_message'])
                                            <div class="text-xs text-gray-400 mt-1">{{ $doc['error_message'] }}</div>
                                        @endif
                                    </td>
                                    <td class="py-2 pe-4 text-gray-500">{{ $doc['page_count'] ?? '—' }}</td>
                                    <td class="py-2 pe-4 text-gray-500">
                                        {{ $doc['cost_toman'] !== null ? \App\Support\Numbers::format($doc['cost_toman']) . ' ' . __('common.toman') : '—' }}
                                    </td>
                                    <td class="py-2 text-gray-500">{{ \App\Support\Jalali::dateTime($doc['created_at']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
