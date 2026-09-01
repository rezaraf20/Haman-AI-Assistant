<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">موجودی فعلی</x-slot>
            <p class="text-3xl font-bold">{{ number_format($this->getWalletBalance()) }} تومان</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">شارژ کیف پول</x-slot>
            <form wire:submit="topup">
                {{ $this->form }}
                <x-filament::button type="submit" class="mt-4">
                    پرداخت و شارژ
                </x-filament::button>
            </form>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">تاریخچه تراکنش‌ها</x-slot>
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
