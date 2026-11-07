@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboard.customer_chatbot_status')">
        <x-slot name="headerEnd">
            <a href="{{ $this->manageUrl() }}" class="hp-link hp-small">{{ __('portal.chatbot_status_manage') }}</a>
        </x-slot>
        @if (empty($rows))
            <div class="hp-empty hp-empty--compact">
                <p class="hp-empty__desc">{{ __('portal.chatbot_status_empty') }}</p>
            </div>
        @else
            <ul class="hp-list">
                @foreach ($rows as $row)
                    <li class="hp-list__item">
                        <div class="hp-list__main">
                            <div class="hp-strong hp-truncate">{{ $row['name'] }}</div>
                            <div class="hp-small hp-muted">{{ $this->lastSyncLabel($row['last_sync']) }}</div>
                            <div class="hp-small hp-muted">{{ __('portal.chatbot_status_documents', ['count' => $row['documents']]) }}</div>
                        </div>
                        <span class="hp-badge hp-badge--{{ $this->statusTone($row['status']) }}">{{ $this->statusLabel($row['status']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
