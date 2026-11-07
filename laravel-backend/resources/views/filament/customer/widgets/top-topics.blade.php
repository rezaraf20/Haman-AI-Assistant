@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboard.customer_top_topics')" :description="__('portal.top_topics_desc')">
        @if (empty($rows))
            <div class="hp-empty hp-empty--compact">
                <p class="hp-empty__desc">{{ __('dashboard.customer_top_topics_empty') }}</p>
            </div>
        @else
            <ul class="hp-list">
                @foreach ($rows as $row)
                    <li class="hp-list__item">
                        <span class="hp-list__main hp-truncate" title="{{ $row->content }}">{{ $row->content }}</span>
                        <span class="hp-strong">{{ $this->fmt($row->cnt) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
