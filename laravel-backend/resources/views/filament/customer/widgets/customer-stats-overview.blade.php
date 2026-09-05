@php $d = $this->getData(); @endphp
<x-filament-widgets::widget>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('dashboard.customer_stats_questions_month') }}</div>
            <div class="text-2xl font-bold mt-1">{{ $this->fmt($d['questions_month']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('dashboard.customer_stats_tokens_remaining') }}</div>
            @if ($d['tokens_pct'] === null)
                <div class="text-2xl font-bold mt-1">{{ __('dashboard.customer_stats_tokens_unlimited') }}</div>
            @else
                <div class="text-2xl font-bold mt-1">{{ $this->fmt(max(0, $d['tokens_limit'] - $d['tokens_used'])) }}</div>
                <div class="w-full bg-gray-200 rounded-full h-2 mt-2 dark:bg-gray-700">
                    <div
                        class="h-2 rounded-full {{ $d['tokens_pct'] >= 90 ? 'bg-danger-500' : ($d['tokens_pct'] >= 70 ? 'bg-warning-500' : 'bg-primary-500') }}"
                        style="width: {{ $d['tokens_pct'] }}%"
                    ></div>
                </div>
                <div class="text-xs text-gray-500 mt-1">{{ $d['tokens_pct'] }}% {{ __('chatbot.usage_of', ['used' => $this->fmt($d['tokens_used']), 'limit' => $this->fmt($d['tokens_limit'])]) }}</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('dashboard.customer_stats_unanswered') }}</div>
            <div class="text-2xl font-bold mt-1 {{ $d['unanswered'] > 0 ? 'text-danger-600' : '' }}">{{ $this->fmt($d['unanswered']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('dashboard.customer_stats_wallet_balance') }}</div>
            <div class="text-2xl font-bold mt-1">{{ $this->toman($d['wallet_toman']) }}</div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
