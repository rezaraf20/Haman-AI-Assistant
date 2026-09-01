<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">وضعیت فعلی</x-slot>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-500">موجودی کیف پول</p>
                    <p class="text-2xl font-bold">{{ number_format($this->getWalletBalance()) }} تومان</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">موجودی توکن اضافه (خریداری‌شده)</p>
                    <p class="text-2xl font-bold">{{ number_format($this->getBonusBalance()) }}</p>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">این توکن‌ها فقط زمانی مصرف می‌شوند که سقف توکن ماهانه‌ی پلن شما تمام شده باشد و تا زمان مصرف، هر ماه باقی می‌مانند.</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">بسته‌های توکن</x-slot>
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
