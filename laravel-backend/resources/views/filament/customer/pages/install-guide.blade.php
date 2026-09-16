@php
    $states = $this->states();
    $current = $this->currentStep();
    $keys = \App\Filament\Customer\Pages\InstallGuide::stepKeys();
    $done = collect($states)->filter()->count();
@endphp

<x-filament-panels::page>

    {{-- Progress --}}
    <x-filament::section>
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <p class="text-sm text-gray-500">{{ __('install_guide.progress', ['done' => $done, 'total' => count($keys)]) }}</p>
                @if ($done < count($keys))
                    <p class="font-medium mt-1">{{ __('install_guide.next_up', ['step' => __("install_guide.step_{$current}_title")]) }}</p>
                @else
                    <p class="font-medium mt-1 text-success-600">{{ __('install_guide.all_done') }}</p>
                @endif
            </div>
            <div class="flex gap-1.5">
                @foreach ($keys as $k)
                    <span class="h-2 w-10 rounded-full {{ $states[$k] ? 'bg-success-500' : 'bg-gray-200 dark:bg-gray-700' }}"></span>
                @endforeach
            </div>
        </div>
    </x-filament::section>

    {{-- Steps. The first unfinished one is open; finished ones collapse. --}}
    @foreach ($keys as $i => $key)
        @php $isDone = $states[$key]; @endphp

        <x-filament::section
            :collapsible="true"
            :collapsed="$isDone || ($key !== $current && ! $isDone && $key !== $keys[0] && $current !== $key)"
            class="mt-4"
        >
            <x-slot name="heading">
                <span class="inline-flex items-center gap-2">
                    <span @class([
                        'inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold',
                        'bg-success-100 text-success-700' => $isDone,
                        'bg-primary-100 text-primary-700' => ! $isDone && $key === $current,
                        'bg-gray-100 text-gray-500 dark:bg-gray-800' => ! $isDone && $key !== $current,
                    ])>{{ $isDone ? '✓' : $i + 1 }}</span>
                    {{ __("install_guide.step_{$key}_title") }}
                </span>
            </x-slot>

            <x-slot name="description">{{ __("install_guide.step_{$key}_intro") }}</x-slot>

            <div class="prose prose-sm max-w-none dark:prose-invert">

                @if ($key === 'chatbot')
                    <p>{{ __('install_guide.step_chatbot_body') }}</p>
                    @if ($this->chatbots()->isEmpty())
                        <p><a href="{{ \App\Filament\Customer\Pages\BuyChatbot::getUrl() }}" class="fi-link">{{ __('install_guide.step_chatbot_cta') }}</a></p>
                    @else
                        <ul>
                            @foreach ($this->chatbots() as $bot)
                                <li><strong>{{ $bot->name }}</strong> — <code>{{ $bot->chatbot_id }}</code></li>
                            @endforeach
                        </ul>
                    @endif

                @elseif ($key === 'download')
                    <p>{{ __('install_guide.step_download_body') }}</p>
                    <ol>
                        <li>{{ __('install_guide.step_download_1') }}</li>
                        <li>{{ __('install_guide.step_download_2') }}</li>
                        <li>{{ __('install_guide.step_download_3') }}</li>
                    </ol>
                    <p>
                        <a href="{{ $this->pluginDownloadUrl() }}" class="fi-link">{{ __('install_guide.step_download_cta') }}</a>
                    </p>

                    {{-- Upgrading from 1.x: the folder name changed, so
                         WordPress sees a different plugin and the old one has
                         to be removed by hand. Settings survive that, but
                         only if "delete data" is left unticked. --}}
                    <div class="not-prose rounded-lg border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-950/40">
                        <p class="font-semibold">{{ __('install_guide.upgrade_title') }}</p>
                        <p class="text-sm mt-1">{{ __('install_guide.upgrade_intro') }}</p>
                        <p class="text-sm mt-2 font-medium">{{ __('install_guide.upgrade_keeps_settings') }}</p>
                        <ol class="text-sm mt-2 list-decimal ps-5 space-y-1">
                            <li>{{ __('install_guide.upgrade_1') }}</li>
                            <li>{{ __('install_guide.upgrade_2') }}</li>
                            <li><strong>{{ __('install_guide.upgrade_3') }}</strong></li>
                            <li>{{ __('install_guide.upgrade_4') }}</li>
                        </ol>
                        <p class="text-sm mt-2">{{ __('install_guide.upgrade_verify') }}</p>
                        <p class="text-xs mt-2 text-gray-600 dark:text-gray-400">{{ __('install_guide.upgrade_why') }}</p>
                    </div>

                @elseif ($key === 'connect')
                    <p>{{ __('install_guide.step_connect_body') }}</p>
                    <table>
                        <tbody>
                            <tr>
                                <td><strong>{{ __('install_guide.field_api_url') }}</strong></td>
                                <td><code>{{ $this->apiBaseUrl() }}</code></td>
                            </tr>
                            <tr>
                                <td><strong>{{ __('install_guide.field_chatbot_id') }}</strong></td>
                                <td>
                                    @forelse ($this->chatbots() as $bot)
                                        <code>{{ $bot->chatbot_id }}</code><br>
                                    @empty
                                        <em>{{ __('install_guide.field_none_yet') }}</em>
                                    @endforelse
                                </td>
                            </tr>
                            <tr>
                                <td><strong>{{ __('install_guide.field_api_key') }}</strong></td>
                                <td>
                                    @forelse ($this->apiKeys() as $key_)
                                        <code>{{ $key_->key_prefix }}…</code>
                                        <span class="text-xs text-gray-500">
                                            {{ $key_->last_used_at
                                                ? __('install_guide.key_used', ['when' => \App\Support\Jalali::dateTime($key_->last_used_at)])
                                                : __('install_guide.key_never_used') }}
                                        </span><br>
                                    @empty
                                        <em>{{ __('install_guide.field_none_yet') }}</em>
                                    @endforelse
                                    <div class="text-xs text-gray-500 mt-1">{{ __('install_guide.key_shown_once') }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p><a href="{{ \App\Filament\Customer\Pages\ApiKeys::getUrl() }}" class="fi-link">{{ __('install_guide.step_connect_cta') }}</a></p>
                    <p>{{ __('install_guide.step_connect_test') }}</p>

                @elseif ($key === 'sync')
                    <p>{{ __('install_guide.step_sync_body') }}</p>
                    <p><a href="{{ \App\Filament\Customer\Pages\MyChatbots::getUrl() }}" class="fi-link">{{ __('install_guide.step_sync_cta') }}</a></p>

                @elseif ($key === 'tools')
                    <p>{{ __('install_guide.step_tools_body') }}</p>
                    <p class="text-sm">{{ __('install_guide.step_tools_cost') }}</p>
                    <p><a href="{{ \App\Filament\Customer\Pages\MyChatbots::getUrl() }}" class="fi-link">{{ __('install_guide.step_tools_cta') }}</a></p>
                @endif

            </div>
        </x-filament::section>
    @endforeach

    {{-- Troubleshooting: the failures actually seen in production --}}
    <x-filament::section :collapsible="true" :collapsed="true" class="mt-4">
        <x-slot name="heading">{{ __('install_guide.trouble_title') }}</x-slot>
        <x-slot name="description">{{ __('install_guide.trouble_intro') }}</x-slot>

        <div class="space-y-5">
            @foreach (['404', 'firewall', 'badzip', 'outdated', 'nowoo', 'secret'] as $case)
                <div>
                    <p class="font-semibold">{{ __("install_guide.trouble_{$case}_symptom") }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ __("install_guide.trouble_{$case}_cause") }}</p>
                    <p class="text-sm mt-1"><strong>{{ __('install_guide.trouble_fix') }}</strong> {{ __("install_guide.trouble_{$case}_fix") }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

</x-filament-panels::page>
