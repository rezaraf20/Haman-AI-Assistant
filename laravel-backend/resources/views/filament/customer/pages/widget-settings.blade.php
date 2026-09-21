@php
    $d = $this->data;
    $color = $d['primary_color'] ?? config('haman.brand.primary_color');
    $position = $d['position'] ?? 'bottom-right';
@endphp
<x-filament-panels::page>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-filament::section>
            <form wire:submit="save">
                {{ $this->form }}
                <div class="mt-6">
                    <x-filament::button type="submit">
                        {{ __('chatbot.widget_settings_save') }}
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <div>
            <h3 class="text-sm font-medium text-gray-500 mb-2">{{ __('chatbot.widget_preview_label') }}</h3>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden bg-gray-50 dark:bg-gray-900" style="min-height: 420px; position: relative;">
                <div style="position:absolute; {{ $position === 'bottom-left' ? 'left:16px' : 'right:16px' }}; bottom:16px; width: 300px; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 40px rgba(0,0,0,.18); background: #fff;">
                    <div style="background: {{ $color }}; color: #fff; padding: 14px 16px; display: flex; align-items: center; gap: 8px;">
                        @if (!empty($d['avatar_url']))
                            <img src="{{ $d['avatar_url'] }}" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        @endif
                        <div>
                            <div style="font-size:15px; font-weight:600;">{{ $d['chat_title'] ?? '' }}</div>
                            <div style="font-size:12px; opacity:.85;">{{ $d['ai_name'] ?? '' }}</div>
                        </div>
                    </div>
                    <div style="padding: 16px; min-height: 120px;">
                        <div style="background:#f1f3f5; border-radius:12px; padding:10px 14px; font-size:14px; max-width:85%; color:#111;">
                            {{ $d['welcome_message'] ?? '' }}
                        </div>
                    </div>
                    @if (!empty($d['quick_questions']))
                        <div style="padding: 0 16px 16px; display:flex; flex-wrap:wrap; gap:6px;">
                            @foreach (array_slice($d['quick_questions'], 0, 4) as $qq)
                                <span style="border:1px solid {{ $color }}; color: {{ $color }}; border-radius: 999px; padding: 4px 10px; font-size: 12px;">{{ $qq['question'] ?? '' }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">{{ __('chatbot.widget_preview_note') }}</p>
        </div>
    </div>
</x-filament-panels::page>
