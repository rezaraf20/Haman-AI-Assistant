<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ __('wallet.balance_stat') }}</x-slot>
            <p class="text-3xl font-bold">{{ $this->getWalletBalance() }}</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('wallet.topup_heading') }}</x-slot>
            @if ($this->canTopUp())
                <form wire:submit="topup">
                    {{ $this->form }}
                    <x-filament::button type="submit" class="mt-4">
                        {{ __('wallet.topup_submit') }}
                    </x-filament::button>
                </form>
            @else
                <p class="text-sm text-gray-500">{{ __('wallet.topup_unavailable_currency') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('wallet.transactions_nav') }}</x-slot>
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
