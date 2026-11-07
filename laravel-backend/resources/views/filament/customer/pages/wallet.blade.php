<x-filament-panels::page>
    <div class="hp-stack">
        <div class="hp-grid hp-grid--wide">
            <div class="hp-card hp-stat">
                <div class="hp-stat__top">
                    <span class="hp-stat__label">{{ __('wallet.balance_stat') }}</span>
                    <x-filament::icon icon="heroicon-o-wallet" class="hp-stat__icon" />
                </div>
                <span class="hp-stat__value">{{ $this->getWalletBalance() }}</span>
            </div>

            <x-filament::section :heading="__('wallet.topup_heading')">
                @if ($this->canTopUp())
                    <form wire:submit="topup" class="hp-stack hp-stack--tight">
                        {{ $this->form }}
                        <div>
                            <x-filament::button type="submit" icon="heroicon-o-plus" wire:target="topup">
                                {{ __('wallet.topup_submit') }}
                            </x-filament::button>
                        </div>
                    </form>
                @else
                    <div class="hp-notice hp-notice--info">
                        <x-filament::icon icon="heroicon-o-information-circle" class="hp-icon hp-notice__icon" />
                        <span>{{ __('wallet.topup_unavailable_currency') }}</span>
                    </div>
                @endif
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('wallet.transactions_nav')">
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
