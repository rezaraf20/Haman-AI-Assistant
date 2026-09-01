<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ $this->record->subject }}</x-slot>
            <x-slot name="description">مشتری: {{ $this->record->tenant->name }} — تاریخ ثبت {{ \App\Support\Jalali::dateTime($this->record->created_at) }}</x-slot>

            <div class="space-y-4">
                @forelse ($this->record->messages as $message)
                    <div class="p-3 rounded-lg {{ $message->sender_type === 'admin' ? 'bg-primary-50 dark:bg-primary-900/20' : 'bg-gray-50 dark:bg-gray-800' }}">
                        <p class="text-xs text-gray-500 mb-1">
                            {{ $message->sender_type === 'admin' ? 'مدیر' : 'مشتری' }} — {{ \App\Support\Jalali::dateTime($message->created_at) }}
                        </p>
                        <p class="whitespace-pre-wrap">{{ $message->body }}</p>
                    </div>
                @empty
                    <p class="text-gray-500">هنوز پیامی ثبت نشده.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">پاسخ</x-slot>
            <form wire:submit="submitReply">
                {{ $this->form }}
                <x-filament::button type="submit" class="mt-4">ذخیره</x-filament::button>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
