@php
    $status = $this->getStatus();
    $connectionHelp = $status['plugin_installed'] ? null : $this->getConnectionHelp();
@endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('dashboard.onboarding_title') }}</x-slot>
        <x-slot name="description">{{ __('dashboard.onboarding_desc') }}</x-slot>

        <ul class="space-y-3">
            <li class="flex items-center gap-3">
                <x-filament::icon
                    :icon="$status['chatbot_created'] ? 'heroicon-o-check-circle' : 'heroicon-o-stop'"
                    class="h-5 w-5 {{ $status['chatbot_created'] ? 'text-success-600' : 'text-gray-400' }}"
                />
                <span class="{{ $status['chatbot_created'] ? 'line-through text-gray-500' : '' }}">
                    {{ __('dashboard.onboarding_chatbot_created') }}
                </span>
                @if (!$status['chatbot_created'])
                    <a href="{{ \App\Filament\Customer\Pages\BuyChatbot::getUrl() }}" class="text-sm text-primary-600 hover:underline">
                        {{ __('dashboard.onboarding_chatbot_created_cta') }}
                    </a>
                @endif
            </li>
            <li class="flex items-center gap-3">
                <x-filament::icon
                    :icon="$status['plugin_installed'] ? 'heroicon-o-check-circle' : 'heroicon-o-stop'"
                    class="h-5 w-5 {{ $status['plugin_installed'] ? 'text-success-600' : 'text-gray-400' }}"
                />
                <span class="{{ $status['plugin_installed'] ? 'line-through text-gray-500' : '' }}">
                    {{ __('dashboard.onboarding_plugin_installed') }}
                </span>
            </li>
            @if ($connectionHelp)
                <li class="ms-8 -mt-1 rounded-lg border border-warning-200 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
                    <p class="text-sm font-medium text-warning-700 dark:text-warning-400">
                        {{ __('dashboard.onboarding_awaiting_connection') }}
                    </p>
                    <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                        <li>{{ __('dashboard.onboarding_awaiting_connection_step1') }}</li>
                        <li>{{ __('dashboard.onboarding_awaiting_connection_step2') }}</li>
                        <li>{{ __('dashboard.onboarding_awaiting_connection_step3') }}</li>
                    </ul>
                    @if ($connectionHelp['key'])
                        <label class="mt-3 block text-xs text-gray-500">{{ __('dashboard.onboarding_awaiting_connection_key_label') }}</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input
                                type="text" readonly id="haman-onboarding-api-key"
                                value="{{ $connectionHelp['key'] }}"
                                class="flex-1 rounded-md border-gray-300 bg-white font-mono text-xs dark:border-gray-600 dark:bg-gray-800"
                            />
                            <button
                                type="button"
                                onclick="navigator.clipboard.writeText(document.getElementById('haman-onboarding-api-key').value)"
                                class="rounded-md border border-gray-300 px-2 py-1 text-xs hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-700"
                            >{{ __('common.copy') }}</button>
                        </div>
                    @else
                        <p class="mt-3 text-sm text-danger-600">{{ __('dashboard.onboarding_awaiting_connection_no_key') }}</p>
                    @endif
                </li>
            @endif
            <li class="flex items-center gap-3">
                <x-filament::icon
                    :icon="$status['first_sync_done'] ? 'heroicon-o-check-circle' : 'heroicon-o-stop'"
                    class="h-5 w-5 {{ $status['first_sync_done'] ? 'text-success-600' : 'text-gray-400' }}"
                />
                <span class="{{ $status['first_sync_done'] ? 'line-through text-gray-500' : '' }}">
                    {{ __('dashboard.onboarding_first_sync') }}
                </span>
            </li>
            <li class="flex items-center gap-3">
                <x-filament::icon
                    :icon="$status['first_conversation_done'] ? 'heroicon-o-check-circle' : 'heroicon-o-stop'"
                    class="h-5 w-5 {{ $status['first_conversation_done'] ? 'text-success-600' : 'text-gray-400' }}"
                />
                <span class="{{ $status['first_conversation_done'] ? 'line-through text-gray-500' : '' }}">
                    {{ __('dashboard.onboarding_first_conversation') }}
                </span>
            </li>
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
