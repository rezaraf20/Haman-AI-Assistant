@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('dashboard.customer_chatbot_status') }}</x-slot>

        <ul class="space-y-3">
            @foreach ($rows as $row)
                <li class="flex items-center justify-between gap-2">
                    <div>
                        <div class="text-sm font-medium">{{ $row['name'] }}</div>
                        <div class="text-xs text-gray-500">{{ $this->lastSyncLabel($row['last_sync']) }}</div>
                    </div>
                    <x-filament::badge :color="$this->statusColor($row['status'])">
                        {{ $this->statusLabel($row['status']) }}
                    </x-filament::badge>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
