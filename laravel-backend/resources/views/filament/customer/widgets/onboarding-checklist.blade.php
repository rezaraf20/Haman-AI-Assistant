@php
    $items = $this->getItems();
    $status = $this->getStatus();
    $connectionHelp = $status['plugin_installed'] ? null : $this->getConnectionHelp();
    // The first unfinished step gets the one filled button; the rest stay quiet.
    $nextKey = collect($items)->first(fn ($i) => !$i['done'] && $i['cta'])['key'] ?? null;
@endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboard.onboarding_title')" :description="__('dashboard.onboarding_desc')">
        <div class="hp-stack hp-stack--tight">
            <div class="hp-row hp-row--between">
                <span class="hp-small hp-strong">{{ $this->progressLabel($items) }}</span>
            </div>
            <div
                class="hp-progress"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="{{ $this->progressPercent($items) }}"
                aria-label="{{ $this->progressLabel($items) }}"
            >
                <div class="hp-progress__fill" style="width: {{ $this->progressPercent($items) }}%"></div>
            </div>

            <ol class="hp-checklist">
                @foreach ($items as $item)
                    <li @class(['hp-checklist__item', 'hp-checklist__item--done' => $item['done']])>
                        <x-filament::icon
                            :icon="$item['done'] ? 'heroicon-s-check-circle' : 'heroicon-o-stop-circle'"
                            class="hp-checklist__mark"
                        />
                        <div class="hp-checklist__body">
                            <div class="hp-checklist__label">
                                {{ $item['label'] }}
                                <span class="sr-only">— {{ $item['done'] ? __('portal.onboarding_done') : __('portal.onboarding_todo') }}</span>
                            </div>
                            @unless ($item['done'])
                                <div class="hp-checklist__hint">{{ $item['hint'] }}</div>
                            @endunless
                        </div>
                        @if (!$item['done'] && $item['cta'])
                            <x-filament::button
                                tag="a"
                                :href="$item['cta']['url']"
                                size="sm"
                                :color="$item['key'] === $nextKey ? 'primary' : 'gray'"
                                :target="$item['cta']['external'] ? '_blank' : null"
                            >
                                {{ $item['cta']['label'] }}
                            </x-filament::button>
                        @endif

                        @if ($item['key'] === 'plugin' && $connectionHelp)
                            <div class="hp-checklist__detail hp-notice hp-notice--warning">
                                <x-filament::icon icon="heroicon-o-link" class="hp-icon hp-notice__icon" />
                                <div class="hp-stack hp-stack--tight hp-grow">
                                    <p class="hp-notice__title">{{ __('dashboard.onboarding_awaiting_connection') }}</p>
                                    <ol class="hp-plain-list">
                                        <li>{{ __('dashboard.onboarding_awaiting_connection_step1') }}</li>
                                        <li>{{ __('dashboard.onboarding_awaiting_connection_step2') }}</li>
                                        <li>{{ __('dashboard.onboarding_awaiting_connection_step3') }}</li>
                                    </ol>
                                    <a href="{{ \App\Filament\Customer\Pages\InstallGuide::pluginDownloadUrl() }}" class="hp-link" download>{{ __('portal.quick_download_plugin') }}</a>
                                    @if ($connectionHelp['key'])
                                        <label for="haman-onboarding-api-key" class="hp-small hp-muted">{{ __('dashboard.onboarding_awaiting_connection_key_label') }}</label>
                                        <div class="hp-copy">
                                            <input
                                                type="text" readonly id="haman-onboarding-api-key"
                                                value="{{ $connectionHelp['key'] }}"
                                                class="hp-copy__input hp-mono hp-ltr"
                                            />
                                            <x-filament::button
                                                color="gray"
                                                size="sm"
                                                icon="heroicon-m-clipboard"
                                                x-data="{ copied: false }"
                                                x-on:click="navigator.clipboard.writeText(document.getElementById('haman-onboarding-api-key').value); copied = true; setTimeout(() => copied = false, 2000)"
                                            >
                                                <span x-show="!copied">{{ __('common.copy') }}</span>
                                                <span x-show="copied" x-cloak>{{ __('portal.copied') }}</span>
                                            </x-filament::button>
                                        </div>
                                    @else
                                        <p class="hp-m0 hp-text-danger">{{ __('dashboard.onboarding_awaiting_connection_no_key') }}</p>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
