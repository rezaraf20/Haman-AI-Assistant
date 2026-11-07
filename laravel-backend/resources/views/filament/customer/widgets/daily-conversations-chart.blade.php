@if ($this->hasAnyData())
    @include('filament-widgets::chart-widget')
@else
    <x-filament-widgets::widget class="fi-wi-chart">
        <x-filament::section :heading="$this->getHeading()" :description="$this->getDescription()">
            <div class="hp-empty">
                <x-filament::icon icon="heroicon-o-chart-bar" class="hp-empty__icon" />
                <p class="hp-empty__title">{{ __('portal.chart_daily_empty_title') }}</p>
                <p class="hp-empty__desc">{{ __('portal.chart_daily_empty_desc') }}</p>
            </div>
        </x-filament::section>
    </x-filament-widgets::widget>
@endif
