@php $gaps = $this->getGaps(); @endphp
<x-filament-widgets::widget>
    <div class="hp-notice hp-notice--warning" role="status">
        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="hp-icon hp-notice__icon" />
        <div class="hp-stack hp-stack--tight hp-grow">
            <div>
                <p class="hp-notice__title">{{ __('dashboard.profile_warning_title') }}</p>
                <p class="hp-m0">{{ __('dashboard.profile_warning_desc') }}</p>
            </div>
            <ul class="hp-plain-list hp-stack hp-stack--tight">
                @foreach ($gaps as $gap)
                    <li class="hp-row hp-row--between">
                        <span class="hp-strong">{{ $gap['name'] }}</span>
                        <a
                            href="{{ \App\Filament\Customer\Pages\WidgetSettings::getUrl(['chatbot' => $gap['chatbot_id']]) }}"
                            class="hp-link"
                        >{{ __('dashboard.profile_warning_cta') }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-filament-widgets::widget>
