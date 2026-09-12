@php
    $suggestions = $this->getSuggestions();
@endphp
<x-filament-panels::page>
    <div class="space-y-4">

        <p class="text-sm text-gray-500">{{ __('suggestions.subtitle') }}</p>

        @if (empty($suggestions))
            <x-filament::section>
                <div class="text-center py-6">
                    <h3 class="text-base font-semibold text-gray-700">{{ __('suggestions.empty_title') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 max-w-xl mx-auto">{{ __('suggestions.empty_body') }}</p>
                </div>
            </x-filament::section>
        @else
            @foreach ($suggestions as $s)
                <x-filament::section>
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center justify-center min-w-7 h-7 px-2 rounded-full bg-primary-100 text-primary-700 text-sm font-bold">
                                    {{ number_format($s['count']) }}
                                </span>
                                <h3 class="text-base font-semibold text-gray-800">{{ $s['text'] }}</h3>
                            </div>

                            <p class="mt-2 text-sm text-gray-600">{{ $s['action'] }}</p>

                            @if (! empty($s['params']['examples']))
                                <div class="mt-2 text-xs text-gray-400">
                                    {{ __('suggestions.examples_label') }}
                                    {{ implode(' / ', $s['params']['examples']) }}
                                </div>
                            @endif

                            {{-- The evidence. A count nobody can check is just
                                 an assertion, so every source conversation is
                                 one click away. --}}
                            @if (! empty($s['conversations']))
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-gray-500">{{ __('suggestions.sources_label') }}</span>
                                    @foreach (array_slice($s['conversations'], 0, 8) as $i => $convId)
                                        <a href="{{ \App\Filament\Customer\Pages\Conversations::getUrl() }}?id={{ $convId }}"
                                           target="_blank"
                                           class="text-xs px-2 py-1 rounded border border-gray-300 text-primary-600 hover:bg-gray-50">
                                            {{ __('suggestions.conversation_n', ['n' => $i + 1]) }}
                                        </a>
                                    @endforeach
                                    @if (count($s['conversations']) > 8)
                                        <span class="text-xs text-gray-400">
                                            {{ __('suggestions.and_more', ['n' => count($s['conversations']) - 8]) }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <x-filament::button color="gray" size="sm"
                            wire:click="dismiss('{{ $s['id'] }}')"
                            wire:confirm="{{ __('suggestions.dismiss_confirm') }}">
                            {{ __('suggestions.dismiss') }}
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @endforeach

            <p class="text-xs text-gray-400">{{ __('suggestions.max_note', ['max' => \App\Services\SuggestionEngine::MAX_ACTIVE]) }}</p>
        @endif
    </div>
</x-filament-panels::page>
