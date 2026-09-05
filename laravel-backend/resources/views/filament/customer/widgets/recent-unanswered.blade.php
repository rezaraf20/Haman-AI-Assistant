@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('dashboard.customer_recent_unanswered') }}</x-slot>

        @if (empty($rows))
            <p class="text-sm text-gray-500">{{ __('dashboard.customer_recent_unanswered_empty') }}</p>
        @else
            <ul class="space-y-2 mb-3">
                @foreach ($rows as $row)
                    <li class="text-sm border-b last:border-0 pb-2 last:pb-0 truncate">{{ $row->question }}</li>
                @endforeach
            </ul>
        @endif
        <a href="{{ $this->demandGapUrl() }}" class="text-sm text-primary-600 hover:underline">
            {{ __('dashboard.customer_recent_unanswered_view_all') }}
        </a>
    </x-filament::section>
</x-filament-widgets::widget>
