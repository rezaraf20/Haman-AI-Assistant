@php $m = $this->getMeters(); @endphp
<x-filament-widgets::widget>
    <div class="hp-grid hp-grid--wide">
        <div class="hp-card hp-meter">
            <div class="hp-meter__head">
                <h3 class="hp-card__title">{{ __('portal.meter_quota_title') }}</h3>
                @if ($m['quota']['pct'] !== null)
                    <span class="hp-small hp-muted">{{ __('portal.percent', ['value' => \App\Support\Numbers::format($m['quota']['pct'])]) }}</span>
                @endif
            </div>
            @if ($m['quota']['limit'])
                <p class="hp-strong hp-m0">{{ __('portal.meter_quota_line', ['used' => $m['quota']['used'], 'limit' => $m['quota']['limit_f']]) }}</p>
                <div
                    class="hp-meter__track"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="{{ $m['quota']['pct'] }}"
                    aria-label="{{ __('portal.meter_quota_title') }}"
                >
                    <div class="hp-meter__fill {{ $m['quota']['tone'] !== 'ai' ? 'hp-meter__fill--' . $m['quota']['tone'] : '' }}" style="width: {{ $m['quota']['pct'] }}%"></div>
                </div>
                @if ($m['quota']['tone'] === 'danger')
                    <span class="hp-badge hp-badge--danger">{{ __('portal.meter_quota_exhausted') }}</span>
                @elseif ($m['quota']['tone'] === 'warning')
                    <span class="hp-badge hp-badge--warning">{{ __('portal.meter_quota_near') }}</span>
                @endif
            @else
                <p class="hp-strong hp-m0">{{ __('portal.meter_quota_unlimited') }}</p>
            @endif
            <span class="hp-small hp-muted">{{ __('portal.meter_quota_reset', ['days' => $m['quota']['days'], 'date' => $m['quota']['date']]) }}</span>
        </div>

        <div class="hp-card hp-meter">
            <div class="hp-meter__head">
                <h3 class="hp-card__title">{{ __('portal.meter_bonus_title') }}</h3>
                <span class="hp-badge hp-badge--neutral">{{ __('portal.meter_bonus_no_expiry') }}</span>
            </div>
            <p class="hp-strong hp-m0">{{ __('portal.meter_bonus_line', ['amount' => $m['bonus']['amount_f']]) }}</p>
            <span class="hp-small hp-muted">{{ __('chatbot.bonus_tokens_desc') }}</span>
            @if ($m['bonus']['amount'] === 0)
                <a href="{{ $m['bonus']['buy_url'] }}" class="hp-link hp-small">{{ __('plan.buy_tokens_nav') }}</a>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
