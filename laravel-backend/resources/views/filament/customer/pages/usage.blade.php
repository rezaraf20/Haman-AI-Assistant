@php
    $data = $this->getUsageData();
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">مصرف توکن (ماه جاری)</x-slot>
            @php
                $used  = $data['tokens']['used'];
                $limit = $data['tokens']['limit'];
                $pct   = $limit ? min(100, round(($used / max($limit, 1)) * 100)) : 0;
            @endphp
            <p class="mb-2">{{ number_format($used) }} / {{ $limit ? number_format($limit) : 'نامحدود' }} توکن</p>
            @if ($limit)
                <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                    <div class="h-2.5 rounded-full {{ $pct >= 100 ? 'bg-danger-500' : 'bg-primary-500' }}" style="width: {{ $pct }}%"></div>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">تعداد پیام (ماه جاری)</x-slot>
            @php
                $mUsed  = $data['messages']['used'];
                $mLimit = $data['messages']['limit'];
                $mPct   = $mLimit ? min(100, round(($mUsed / max($mLimit, 1)) * 100)) : 0;
            @endphp
            <p class="mb-2">{{ number_format($mUsed) }} / {{ $mLimit ? number_format($mLimit) : 'نامحدود' }} پیام</p>
            @if ($mLimit)
                <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                    <div class="h-2.5 rounded-full {{ $mPct >= 100 ? 'bg-danger-500' : 'bg-primary-500' }}" style="width: {{ $mPct }}%"></div>
                </div>
            @endif
        </x-filament::section>

        @if ($data['bonus_tokens'] > 0)
            <x-filament::section>
                <x-slot name="heading">توکن اضافه خریداری‌شده</x-slot>
                <p class="text-2xl font-bold">{{ number_format($data['bonus_tokens']) }}</p>
                <p class="text-xs text-gray-500 mt-1">این‌ها بعد از تمام‌شدن سقف ماهانه‌ی پلن شما مصرف می‌شوند و منقضی نمی‌شوند.</p>
            </x-filament::section>
        @endif

        <p class="text-sm text-gray-500">تاریخ ریست ماهانه: {{ \App\Support\Jalali::date($data['resets_at']) }}</p>
    </div>
</x-filament-panels::page>
