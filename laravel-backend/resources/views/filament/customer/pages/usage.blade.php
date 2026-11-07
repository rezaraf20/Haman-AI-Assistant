@php
    use App\Support\Numbers;
    $data = $this->getUsageData();
    $meters = [
        ['title' => __('chatbot.token_usage_heading'), 'used' => $data['tokens']['used'], 'limit' => $data['tokens']['limit'], 'unit' => __('chatbot.tokens_unit'), 'ai' => true],
        ['title' => __('chatbot.message_usage_heading'), 'used' => $data['messages']['used'], 'limit' => $data['messages']['limit'], 'unit' => __('chatbot.messages_unit'), 'ai' => false],
    ];
@endphp
<x-filament-panels::page>
    <div class="hp-stack">
        <div class="hp-grid hp-grid--wide">
            @foreach ($meters as $m)
                @php $pct = $m['limit'] ? min(100, (int) round($m['used'] / max($m['limit'], 1) * 100)) : null; @endphp
                <div class="hp-card hp-meter">
                    <div class="hp-meter__head">
                        <h3 class="hp-card__title">{{ $m['title'] }}</h3>
                        @if ($pct !== null)
                            <span class="hp-small hp-muted">{{ __('portal.percent', ['value' => Numbers::format($pct)]) }}</span>
                        @endif
                    </div>
                    <p class="hp-strong hp-m0">
                        {{ Numbers::format($m['used']) }} / {{ $m['limit'] ? Numbers::format($m['limit']) : __('common.unlimited') }} {{ $m['unit'] }}
                    </p>
                    @if ($pct !== null)
                        <div class="hp-meter__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}" aria-label="{{ $m['title'] }}">
                            <div class="hp-meter__fill {{ $pct >= 100 ? 'hp-meter__fill--danger' : '' }}" style="width: {{ $pct }}%"></div>
                        </div>
                        @if ($pct >= 100)
                            <span class="hp-badge hp-badge--danger">{{ __('portal.meter_quota_exhausted') }}</span>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        <div class="hp-card hp-meter">
            <div class="hp-meter__head">
                <h3 class="hp-card__title">{{ __('chatbot.bonus_tokens_heading') }}</h3>
                <span class="hp-badge hp-badge--neutral">{{ __('portal.meter_bonus_no_expiry') }}</span>
            </div>
            <p class="hp-strong hp-m0">{{ __('portal.meter_bonus_line', ['amount' => Numbers::format($data['bonus_tokens'])]) }}</p>
            <span class="hp-small hp-muted">{{ __('chatbot.bonus_tokens_desc') }}</span>
        </div>

        <p class="hp-small hp-muted hp-m0">{{ __('chatbot.monthly_reset_date') }} {{ \App\Support\Jalali::date($data['resets_at']) }}</p>
    </div>
</x-filament-panels::page>
