<x-filament-panels::page>
    <div class="hp-stack">
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('billing.how_it_works', ['grace' => \App\Support\Numbers::format((int) \App\Support\Settings::get('limits.billing_grace_days'))]) }}</p>
        </x-filament::section>

        <x-filament::section>
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
