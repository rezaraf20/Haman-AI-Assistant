@php $gaps = $this->getGaps(); @endphp
<x-filament-widgets::widget>
    <x-filament::section color="warning">
        <x-slot name="heading">{{ __('dashboard.profile_warning_title') }}</x-slot>
        <x-slot name="description">{{ __('dashboard.profile_warning_desc') }}</x-slot>

        <ul class="space-y-2">
            @foreach ($gaps as $gap)
                <li class="flex items-center justify-between gap-3">
                    <span class="text-sm">{{ $gap['name'] }}</span>
                    <a
                        href="{{ \App\Filament\Customer\Pages\WidgetSettings::getUrl(['chatbot' => $gap['chatbot_id']]) }}"
                        class="text-sm text-primary-600 hover:underline shrink-0"
                    >
                        {{ __('dashboard.profile_warning_cta') }}
                    </a>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
