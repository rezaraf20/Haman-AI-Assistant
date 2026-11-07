@php
    $attachments = $this->getAttachments();
    $tone = fn ($s) => match ($s) { 'indexed' => 'success', 'skipped' => 'warning', 'failed' => 'danger', 'processing' => 'info', default => 'neutral' };
    $label = fn ($s) => match ($s) {
        'indexed' => __('datasheets.status_indexed'),
        'skipped' => __('datasheets.status_skipped'),
        'failed' => __('datasheets.status_failed'),
        'processing' => __('datasheets.status_processing'),
        default => __('datasheets.status_pending'),
    };
@endphp
<x-filament-panels::page>
    @if (empty($attachments))
        <div class="hp-card">
            <div class="hp-empty">
                <x-filament::icon icon="heroicon-o-document-text" class="hp-empty__icon" />
                <p class="hp-empty__title">{{ __('datasheets.empty') }}</p>
                <p class="hp-empty__desc">{{ __('portal.datasheets_empty_desc') }}</p>
            </div>
        </div>
    @else
        <div class="hp-table-wrap">
            <table class="hp-table">
                <thead>
                    <tr>
                        <th>{{ __('datasheets.col_file') }}</th>
                        <th>{{ __('datasheets.col_product') }}</th>
                        <th>{{ __('datasheets.col_status') }}</th>
                        <th class="hp-num">{{ __('datasheets.col_pages') }}</th>
                        <th class="hp-num">{{ __('datasheets.col_cost') }}</th>
                        <th>{{ __('common.created_at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($attachments as $doc)
                        <tr wire:key="doc-{{ $doc['id'] }}">
                            <td class="hp-strong">{{ $doc['title'] }}</td>
                            <td class="hp-muted">{{ $doc['product_name'] ?? '—' }}</td>
                            <td>
                                <span class="hp-badge hp-badge--{{ $tone($doc['status']) }}">{{ $label($doc['status']) }}</span>
                                @if ($doc['error_message'])
                                    <div class="hp-small hp-muted">{{ $doc['error_message'] }}</div>
                                @endif
                            </td>
                            <td class="hp-num">{{ $doc['page_count'] !== null ? \App\Support\Numbers::format($doc['page_count']) : '—' }}</td>
                            {{-- 0 here means the embedding price was never configured
                                 (EMBEDDING_PRICE_PER_1M_TOMAN), not that it was free. --}}
                            <td class="hp-num">
                                @if ($doc['cost_toman'] === null)
                                    —
                                @elseif ((float) $doc['cost_toman'] <= 0)
                                    <span class="hp-small hp-muted">{{ __('portal.cost_unpriced') }}</span>
                                @else
                                    {{ \App\Support\Numbers::format($doc['cost_toman']) }} {{ __('common.toman') }}
                                @endif
                            </td>
                            <td class="hp-nowrap hp-muted">{{ \App\Support\Jalali::dateTime($doc['created_at']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
