@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboard.customer_recent_unanswered')" :description="__('portal.unanswered_desc')">
        <x-slot name="headerEnd">
            <a href="{{ $this->demandGapUrl() }}" class="hp-link hp-small">{{ __('dashboard.customer_recent_unanswered_view_all') }}</a>
        </x-slot>

        @if (empty($rows))
            <div class="hp-empty hp-empty--compact">
                <x-filament::icon icon="heroicon-o-check-circle" class="hp-empty__icon" />
                <p class="hp-empty__desc">{{ __('dashboard.customer_recent_unanswered_empty') }}</p>
            </div>
        @else
            <ul class="hp-list">
                @foreach ($rows as $row)
                    <li class="hp-list__item">
                        <span class="hp-list__main hp-truncate" title="{{ $row->question }}">{{ $row->question }}</span>
                        <span class="hp-small hp-muted hp-nowrap">{{ $this->when($row->created_at) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
