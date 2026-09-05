@php $status = $this->getStatus(); @endphp
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
