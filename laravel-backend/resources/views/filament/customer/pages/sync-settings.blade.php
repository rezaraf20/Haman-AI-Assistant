<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="save">
            {{ $this->form }}
            <div class="mt-6 flex items-center gap-3">
                <x-filament::button type="submit">
                    {{ __('chatbot.sync_settings_save') }}
                </x-filament::button>
                <x-filament::button
                    type="button"
                    color="danger"
                    outlined
                    wire:click="clearAndReindex"
                    wire:confirm="{{ __('chatbot.sync_settings_clear_and_reindex_confirm') }}"
                >
                    {{ __('chatbot.sync_settings_clear_and_reindex') }}
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
