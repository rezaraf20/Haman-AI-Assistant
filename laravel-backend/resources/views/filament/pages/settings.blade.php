<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-4 flex items-center gap-3">
            <x-filament::button type="submit">{{ __('common.save') }}</x-filament::button>
            <span class="text-xs text-gray-500">{{ __('settings.save_hint') }}</span>
        </div>
    </form>
</x-filament-panels::page>
