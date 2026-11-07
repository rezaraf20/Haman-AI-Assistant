@if ($this->hasAnyData())
    @include('filament-widgets::chart-widget')
@else
    <x-filament-widgets::widget class="fi-wi-chart">
        <x-filament::section :heading="$this->getHeading()" :description="$this->getDescription()">
            <div class="hp-empty">
                <x-filament::icon icon="heroicon-o-sparkles" class="hp-empty__icon" />
                <p class="hp-empty__desc">{{ __('dashboard.customer_chart_intents_empty') }}</p>
            </div>
        </x-filament::section>
    </x-filament-widgets::widget>
@endif
