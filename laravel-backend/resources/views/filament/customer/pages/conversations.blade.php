@php
    $transcript = $this->getTranscript();
@endphp
<x-filament-panels::page>
    <div class="space-y-4">
        @if (empty($transcript))
            <x-filament::section>
                <p class="text-sm text-gray-500">{{ __('suggestions.conversation_not_found') }}</p>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="text-xs text-gray-500 mb-4">
                    <span dir="ltr">{{ \App\Support\Jalali::dateTime($transcript['conversation']->started_at ?? $transcript['conversation']->created_at) }}</span>
                </div>

                <div class="space-y-3">
                    @foreach ($transcript['messages'] as $m)
                        <div @class([
                            'rounded-lg px-3 py-2 text-sm max-w-3xl',
                            'bg-primary-50 text-gray-800 ms-auto' => $m->role === 'user',
                            'bg-gray-100 text-gray-700' => $m->role !== 'user',
                        ])>
                            <div class="text-[11px] text-gray-500 mb-1">
                                {{ $m->role === 'user' ? __('suggestions.role_user') : __('suggestions.role_bot') }}
                                @if (! empty($m->is_unanswered))
                                    <span class="text-danger-600">· {{ __('suggestions.flagged_unanswered') }}</span>
                                @endif
                            </div>
                            <div class="whitespace-pre-wrap">{{ $m->content }}</div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
