@php
    $transcript = $this->getTranscript();
@endphp
<x-filament-panels::page>
    @if (empty($transcript))
        <div class="hp-card">
            <div class="hp-empty">
                <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="hp-empty__icon" />
                <p class="hp-empty__desc">{{ __('suggestions.conversation_not_found') }}</p>
            </div>
        </div>
    @else
        <div class="hp-card hp-stack hp-stack--tight">
            <p class="hp-small hp-muted hp-m0">
                <span class="hp-ltr">{{ \App\Support\Jalali::dateTime($transcript['conversation']->started_at ?? $transcript['conversation']->created_at) }}</span>
            </p>
            {{-- Visitor messages sit on the end side; bot replies carry the AI
                 accent rule on their start edge. --}}
            @foreach ($transcript['messages'] as $m)
                <div @class(['hp-bubble', 'hp-bubble--user' => $m->role === 'user', 'hp-bubble--bot' => $m->role !== 'user'])>
                    <div class="hp-bubble__meta">
                        <span>{{ $m->role === 'user' ? __('suggestions.role_user') : __('suggestions.role_bot') }}</span>
                        @if (! empty($m->is_unanswered))
                            <span class="hp-badge hp-badge--danger">{{ __('suggestions.flagged_unanswered') }}</span>
                        @endif
                    </div>
                    <div class="hp-prewrap">{{ $m->content }}</div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
