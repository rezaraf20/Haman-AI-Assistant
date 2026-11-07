@php $d = $this->getData(); @endphp
<x-filament-panels::page>
    @if ($d === null)
        <div class="hp-card">
            <div class="hp-empty">
                <x-filament::icon icon="heroicon-o-book-open" class="hp-empty__icon" />
                <p class="hp-empty__title">{{ __('portal.knowledge_no_chatbot') }}</p>
            </div>
        </div>
    @else
        <div class="hp-stack">
            @if (count($d['chatbots']) > 1)
                <div class="hp-row" role="group" aria-label="{{ __('chatbot.singular') }}">
                    @foreach ($d['chatbots'] as $id => $name)
                        <a href="?chatbot={{ $id }}" class="hp-chip" aria-pressed="{{ $id === $d['chatbotId'] ? 'true' : 'false' }}">{{ $name }}</a>
                    @endforeach
                </div>
            @endif

            @if ($d['overLimit'] > 0)
                <div class="hp-notice hp-notice--warning" role="status">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="hp-icon hp-notice__icon" />
                    <div>
                        <p class="hp-notice__title">{{ __('portal.knowledge_over_limit_title', ['count' => \App\Support\Numbers::format($d['overLimit'])]) }}</p>
                        <p class="hp-m0">{{ __('portal.knowledge_over_limit_body') }}</p>
                    </div>
                </div>
            @endif

            <div class="hp-card hp-meter">
                <div class="hp-meter__head">
                    <h3 class="hp-card__title">{{ __('portal.knowledge_usage_title') }}</h3>
                    @if ($d['pct'] !== null)
                        <span class="hp-small hp-muted">{{ __('portal.percent', ['value' => \App\Support\Numbers::format($d['pct'])]) }}</span>
                    @endif
                </div>
                <p class="hp-strong hp-m0">
                    @if ($d['limit'])
                        {{ __('portal.knowledge_usage_line', ['used' => \App\Support\Numbers::format($d['liveTotal']), 'limit' => \App\Support\Numbers::format($d['limit'])]) }}
                    @else
                        {{ __('portal.knowledge_usage_unlimited', ['used' => \App\Support\Numbers::format($d['liveTotal'])]) }}
                    @endif
                </p>
                @if ($d['pct'] !== null)
                    <div class="hp-meter__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $d['pct'] }}" aria-label="{{ __('portal.knowledge_usage_title') }}">
                        <div class="hp-meter__fill {{ $d['pct'] >= 100 ? 'hp-meter__fill--danger' : '' }}" style="width: {{ $d['pct'] }}%"></div>
                    </div>
                @endif
                <a href="{{ $this->syncSettingsUrl($d['chatbotId']) }}" class="hp-link hp-small">{{ __('portal.knowledge_manage_types') }}</a>
            </div>

            @if (empty($d['rows']))
                <div class="hp-card">
                    <div class="hp-empty">
                        <x-filament::icon icon="heroicon-o-arrow-path" class="hp-empty__icon" />
                        <p class="hp-empty__title">{{ __('portal.knowledge_empty_title') }}</p>
                        <p class="hp-empty__desc">{{ __('portal.knowledge_empty_desc') }}</p>
                    </div>
                </div>
            @else
                <div class="hp-table-wrap">
                    <table class="hp-table">
                        <thead>
                            <tr>
                                <th>{{ __('portal.knowledge_col_kind') }}</th>
                                <th class="hp-num">{{ __('portal.knowledge_col_documents') }}</th>
                                <th class="hp-num">{{ __('portal.knowledge_col_chunks') }}</th>
                                <th>{{ __('portal.knowledge_col_last_sync') }}</th>
                                <th class="hp-num">{{ __('portal.knowledge_col_archived') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['rows'] as $row)
                                <tr>
                                    <td class="hp-strong">{{ $row['label'] }}</td>
                                    <td class="hp-num">{{ $row['documents'] }}</td>
                                    <td class="hp-num">{{ $row['chunks'] }}</td>
                                    <td class="hp-nowrap hp-muted">{{ $row['last_sync'] ?? '—' }}</td>
                                    <td class="hp-num hp-muted">{{ $row['archived'] > 0 ? $row['archived_f'] : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="hp-small hp-muted hp-m0">{{ __('portal.knowledge_footnote') }}</p>
            @endif
        </div>
    @endif
</x-filament-panels::page>
