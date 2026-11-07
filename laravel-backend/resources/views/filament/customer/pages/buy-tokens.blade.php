<x-filament-panels::page>
    <div class="hp-stack">
        <div class="hp-grid hp-grid--wide">
            <div class="hp-card hp-stat">
                <span class="hp-stat__label">{{ __('portal.buy_wallet_balance') }}</span>
                <span class="hp-stat__value">{{ \App\Support\Money::forCurrentTenant($this->getWalletBalance()) }}</span>
            </div>
            <div class="hp-card hp-stat">
                <div class="hp-stat__top">
                    <span class="hp-stat__label">{{ __('portal.buy_tokens_bonus_balance') }}</span>
                    <x-filament::icon icon="heroicon-o-cpu-chip" class="hp-stat__icon hp-stat__icon--ai" />
                </div>
                <span class="hp-stat__value">{{ \App\Support\Numbers::format($this->getBonusBalance()) }}</span>
                <span class="hp-stat__foot">{{ __('portal.buy_tokens_note') }}</span>
            </div>
        </div>

        <x-filament::section :heading="__('portal.buy_tokens_packages')">
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
