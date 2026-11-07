<x-filament-panels::page>
    <div class="hp-stack">
        @if ($newApiKey)
            <div class="hp-notice hp-notice--info" role="status">
                <x-filament::icon icon="heroicon-o-check-circle" class="hp-icon hp-notice__icon" />
                <div class="hp-stack hp-stack--tight hp-grow">
                    <p class="hp-notice__title">{{ __('portal.buy_chatbot_created', ['name' => $newChatbotName]) }}</p>
                    <p class="hp-m0">{{ __('portal.buy_chatbot_key_once') }}</p>
                    <div class="hp-copy">
                        <input type="text" readonly id="haman-new-api-key" value="{{ $newApiKey }}" onclick="this.select()" class="hp-copy__input hp-mono hp-ltr" />
                        <x-filament::button
                            color="gray"
                            size="sm"
                            icon="heroicon-m-clipboard"
                            x-data="{ copied: false }"
                            x-on:click="navigator.clipboard.writeText(document.getElementById('haman-new-api-key').value); copied = true; setTimeout(() => copied = false, 2000)"
                        >
                            <span x-show="!copied">{{ __('common.copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('portal.copied') }}</span>
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">{{ __('portal.buy_wallet_balance') }}: {{ $this->getWalletBalanceDisplay() }}</x-slot>
            <form wire:submit="purchase" class="hp-stack hp-stack--tight">
                {{ $this->form }}
                @if (($data['type'] ?? null))
                    <p class="hp-m0">{{ __('portal.buy_chatbot_cost') }}: <strong>{{ $this->getSelectedPriceDisplay() }}</strong></p>
                @endif
                <div>
                    <x-filament::button type="submit" wire:target="purchase">{{ __('portal.buy_chatbot_submit') }}</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
