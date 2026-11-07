@php
    $data = $this->getDashboardData();
@endphp
<x-filament-panels::page>
    <div class="hp-stack">
        <p class="hp-small hp-muted hp-m0">
            {{ __('chatbot.demand_gap_range', ['from' => \App\Support\Jalali::date($data['range_start']), 'to' => \App\Support\Jalali::date($data['range_end'])]) }}
        </p>

        <div class="hp-notice hp-notice--warning" role="note">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="hp-icon hp-notice__icon" />
            <span>{{ __('chatbot.demand_gap_reliability_warning') }}</span>
        </div>

        <div class="hp-grid">
            <div class="hp-card hp-stat">
                <span class="hp-stat__label">{{ __('chatbot.demand_gap_total_questions') }}</span>
                <span class="hp-stat__value">{{ \App\Support\Numbers::format($data['total_questions']) }}</span>
            </div>
            <div class="hp-card hp-stat">
                <span class="hp-stat__label">{{ __('chatbot.demand_gap_unanswered') }}</span>
                <span class="hp-stat__value">{{ \App\Support\Numbers::format($data['unanswered_count']) }}</span>
                <span class="hp-stat__foot">{{ __('portal.demand_gap_share', ['pct' => \App\Support\Numbers::format($data['unanswered_pct'], fmod((float) $data['unanswered_pct'], 1.0) != 0.0 ? 1 : 0)]) }}</span>
            </div>
        </div>

        <x-filament::section :heading="__('chatbot.demand_gap_unanswered_list_heading')" :description="__('chatbot.demand_gap_unanswered_list_desc')">
            @if (count($data['top_unanswered']) === 0)
                <div class="hp-empty hp-empty--compact">
                    <x-filament::icon icon="heroicon-o-check-circle" class="hp-empty__icon" />
                    <p class="hp-empty__desc">{{ __('chatbot.demand_gap_no_unanswered') }}</p>
                </div>
            @else
                <div class="hp-table-wrap">
                    <table class="hp-table">
                        <thead><tr><th>{{ __('chatbot.demand_gap_question_col') }}</th><th class="hp-num">{{ __('chatbot.demand_gap_count_col') }}</th></tr></thead>
                        <tbody>
                            @foreach ($data['top_unanswered'] as $row)
                                <tr><td>{{ $row->question }}</td><td class="hp-num">{{ \App\Support\Numbers::format($row->cnt) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('chatbot.demand_gap_top_questions_heading')">
            @if ($data['top_questions']->isEmpty())
                <div class="hp-empty hp-empty--compact">
                    <p class="hp-empty__desc">{{ __('chatbot.demand_gap_no_questions') }}</p>
                </div>
            @else
                <div class="hp-table-wrap">
                    <table class="hp-table">
                        <thead><tr><th>{{ __('chatbot.demand_gap_question_col') }}</th><th class="hp-num">{{ __('chatbot.demand_gap_count_col') }}</th></tr></thead>
                        <tbody>
                            @foreach ($data['top_questions'] as $row)
                                <tr><td>{{ $row->content }}</td><td class="hp-num">{{ \App\Support\Numbers::format($row->cnt) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
