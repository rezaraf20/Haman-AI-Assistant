@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('dashboard.customer_top_topics') }}</x-slot>

        @if (empty($rows))
            <p class="text-sm text-gray-500">{{ __('dashboard.customer_top_topics_empty') }}</p>
        @else
            <ul class="space-y-2">
                @foreach ($rows as $row)
                    <li class="flex items-center justify-between gap-2 text-sm border-b last:border-0 pb-2 last:pb-0">
                        <span class="truncate">{{ $row->content }}</span>
                        <span class="shrink-0 text-gray-500">{{ $this->fmt($row->cnt) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
