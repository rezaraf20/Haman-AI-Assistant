<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('panel.account_info_nav') }}</x-slot>
        <form wire:submit="save">
            {{ $this->form }}
            <x-filament::button type="submit" class="mt-4">{{ __('common.save') }}</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
