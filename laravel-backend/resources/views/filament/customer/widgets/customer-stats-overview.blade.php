@php $cards = $this->getCards(); @endphp
<x-filament-widgets::widget>
    <div class="hp-grid">
        @foreach ($cards as $card)
            <div class="hp-card hp-stat">
                <div class="hp-stat__top">
                    <span class="hp-stat__label">{{ $card['label'] }}</span>
                    <x-filament::icon :icon="$card['icon']" class="hp-stat__icon {{ $card['ai'] ? 'hp-stat__icon--ai' : '' }}" />
                </div>
                <div class="hp-stat__value">{{ $card['value'] }}</div>
                @if ($card['trend'])
                    <span class="hp-trend hp-trend--{{ $card['trend']['tone'] }}">
                        <x-filament::icon :icon="$card['trend']['icon']" class="hp-icon--sm hp-flip-rtl" />
                        {{ $card['trend']['text'] }}
                    </span>
                @endif
                <span class="hp-stat__foot">{{ $card['foot'] }}</span>
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
