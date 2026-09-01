<x-filament-panels::page>
    <div class="space-y-6">
        @if ($newApiKey)
            <x-filament::section>
                <x-slot name="heading">چت‌بات «{{ $newChatbotName }}» با موفقیت ساخته شد</x-slot>
                <p class="mb-2 text-sm text-gray-600 dark:text-gray-400">
                    این کلید فقط همین یک‌بار نمایش داده می‌شود. آن را کپی کرده و در تنظیمات پلاگین وردپرس سایت خود
                    (Settings → Hamman AI → API Key) وارد کنید.
                </p>
                <input type="text" readonly value="{{ $newApiKey }}" onclick="this.select()"
                    class="w-full font-mono text-sm p-2 border rounded-lg dark:bg-gray-800 dark:border-gray-700" />
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">موجودی کیف پول: {{ number_format($this->getWalletBalance()) }} تومان</x-slot>
            <form wire:submit="purchase">
                {{ $this->form }}
                @if (($data['type'] ?? null))
                    <p class="mt-3 text-sm">هزینه این خرید: <strong>{{ number_format($this->getSelectedPrice()) }} تومان</strong></p>
                @endif
                <x-filament::button type="submit" class="mt-4">خرید و پرداخت از کیف پول</x-filament::button>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
