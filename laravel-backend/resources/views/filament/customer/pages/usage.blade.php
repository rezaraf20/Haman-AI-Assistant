@php
    $data = $this->getUsageData();
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ __('chatbot.token_usage_heading') }}</x-slot>
            @php
                $used  = $data['tokens']['used'];
                $limit = $data['tokens']['limit'];
                $pct   = $limit ? min(100, round(($used / max($limit, 1)) * 100)) : 0;
            @endphp
            <p class="mb-2">{{ number_format($used) }} / {{ $limit ? number_format($limit) : __('common.unlimited') }} {{ __('chatbot.tokens_unit') }}</p>
            @if ($limit)
                <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                    <div class="h-2.5 rounded-full {{ $pct >= 100 ? 'bg-danger-500' : 'bg-primary-500' }}" style="width: {{ $pct }}%"></div>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('chatbot.message_usage_heading') }}</x-slot>
            @php
                $mUsed  = $data['messages']['used'];
                $mLimit = $data['messages']['limit'];
                $mPct   = $mLimit ? min(100, round(($mUsed / max($mLimit, 1)) * 100)) : 0;
            @endphp
            <p class="mb-2">{{ number_format($mUsed) }} / {{ $mLimit ? number_format($mLimit) : __('common.unlimited') }} {{ __('chatbot.messages_unit') }}</p>
            @if ($mLimit)
                <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                    <div class="h-2.5 rounded-full {{ $mPct >= 100 ? 'bg-danger-500' : 'bg-primary-500' }}" style="width: {{ $mPct }}%"></div>
                </div>
            @endif
        </x-filament::section>

        @if ($data['bonus_tokens'] > 0)
            <x-filament::section>
                <x-slot name="heading">{{ __('chatbot.bonus_tokens_heading') }}</x-slot>
                <p class="text-2xl font-bold">{{ number_format($data['bonus_tokens']) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('chatbot.bonus_tokens_desc') }}</p>
            </x-filament::section>
        @endif

        <p class="text-sm text-gray-500">{{ __('chatbot.monthly_reset_date') }} {{ \App\Support\Jalali::date($data['resets_at']) }}</p>
    </div>
</x-filament-panels::page>
