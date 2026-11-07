<x-filament-widgets::widget>
    <x-filament::section :heading="__('portal.quick_title')">
        <ul class="hp-actions">
            @foreach ($this->getActions() as $action)
                <li>
                    <a href="{{ $action['url'] }}" class="hp-action" @if ($action['download']) download @endif>
                        <x-filament::icon :icon="$action['icon']" class="hp-icon" />
                        <span class="hp-action__label">{{ $action['label'] }}</span>
                        @unless ($action['download'])
                            <x-filament::icon icon="heroicon-m-chevron-right" class="hp-icon--sm hp-flip-rtl hp-muted" />
                        @endunless
                    </a>
                </li>
            @endforeach
        </ul>
        @php $leads = $this->getNewLeads(); @endphp
        <p class="hp-small hp-muted hp-mt">
            {{ __('portal.quick_new_leads', ['count' => $leads['label']]) }}
            @if ($leads['count'] > 0)
                · <a href="{{ $leads['url'] }}" class="hp-link">{{ __('leads.view_all') }}</a>
            @endif
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
