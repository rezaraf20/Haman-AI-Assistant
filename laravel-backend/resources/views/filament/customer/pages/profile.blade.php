<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">اطلاعات حساب کاربری</x-slot>
        <form wire:submit="save">
            {{ $this->form }}
            <x-filament::button type="submit" class="mt-4">ذخیره تغییرات</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
