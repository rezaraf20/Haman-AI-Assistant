@php
    $d = $this->data;
    // The merchant's own widget colour — data they chose, not a portal colour,
    // so it is the one colour value this view writes inline.
    $color = $d['primary_color'] ?? config('haman.brand.primary_color');
    $position = $d['position'] ?? 'bottom-right';
@endphp
<x-filament-panels::page>
    <div class="hp-grid hp-grid--wide">
        <x-filament::section>
            <form wire:submit="save">
                {{ $this->form }}
                <div class="hp-mt">
                    <x-filament::button type="submit" wire:target="save">
                        {{ __('chatbot.widget_settings_save') }}
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <div class="hp-stack hp-stack--tight">
            <h3 class="hp-card__title">{{ __('chatbot.widget_preview_label') }}</h3>
            <div class="hp-preview">
                <div class="hp-preview__window {{ $position === 'bottom-left' ? 'hp-preview__window--left' : 'hp-preview__window--right' }}">
                    <div class="hp-preview__head" style="background-color: {{ $color }}">
                        @if (!empty($d['avatar_url']))
                            <img src="{{ $d['avatar_url'] }}" alt="" class="hp-preview__avatar">
                        @endif
                        <div>
                            <div class="hp-preview__title">{{ $d['chat_title'] ?? '' }}</div>
                            <div class="hp-small">{{ $d['ai_name'] ?? '' }}</div>
                        </div>
                    </div>
                    <div class="hp-preview__body">
                        <div class="hp-preview__bubble">{{ $d['welcome_message'] ?? '' }}</div>
                    </div>
                    @if (!empty($d['quick_questions']))
                        <div class="hp-preview__chips" style="color: {{ $color }}">
                            @foreach (array_slice($d['quick_questions'], 0, 4) as $qq)
                                <span class="hp-preview__chip">{{ $qq['question'] ?? '' }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            <p class="hp-small hp-muted hp-m0">{{ __('chatbot.widget_preview_note') }}</p>
        </div>
    </div>
</x-filament-panels::page>
