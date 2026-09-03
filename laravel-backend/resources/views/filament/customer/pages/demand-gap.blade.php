@php
    $data = $this->getDashboardData();
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-500">
            {{ __('chatbot.demand_gap_range', ['from' => \App\Support\Jalali::date($data['range_start']), 'to' => \App\Support\Jalali::date($data['range_end'])]) }}
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <x-filament::section>
                <x-slot name="heading">{{ __('chatbot.demand_gap_total_questions') }}</x-slot>
                <p class="text-3xl font-bold">{{ number_format($data['total_questions']) }}</p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">{{ __('chatbot.demand_gap_unanswered') }}</x-slot>
                <p class="text-3xl font-bold {{ $data['unanswered_count'] > 0 ? 'text-danger-600' : '' }}">
                    {{ number_format($data['unanswered_count']) }}
                    <span class="text-base font-normal text-gray-500">({{ $data['unanswered_pct'] }}%)</span>
                </p>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">{{ __('chatbot.demand_gap_unanswered_list_heading') }}</x-slot>
            <x-slot name="description">{{ __('chatbot.demand_gap_unanswered_list_desc') }}</x-slot>

            @if (count($data['top_unanswered']) === 0)
                <p class="text-sm text-gray-500">{{ __('chatbot.demand_gap_no_unanswered') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-start text-gray-500 border-b">
                                <th class="py-2 pe-4 text-start">{{ __('chatbot.demand_gap_question_col') }}</th>
                                <th class="py-2 text-start">{{ __('chatbot.demand_gap_count_col') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['top_unanswered'] as $row)
                                <tr class="border-b last:border-0">
                                    <td class="py-2 pe-4">{{ $row->question }}</td>
                                    <td class="py-2">{{ \App\Support\Numbers::format($row->cnt) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('chatbot.demand_gap_top_questions_heading') }}</x-slot>

            @if ($data['top_questions']->isEmpty())
                <p class="text-sm text-gray-500">{{ __('chatbot.demand_gap_no_questions') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-start text-gray-500 border-b">
                                <th class="py-2 pe-4 text-start">{{ __('chatbot.demand_gap_question_col') }}</th>
                                <th class="py-2 text-start">{{ __('chatbot.demand_gap_count_col') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['top_questions'] as $row)
                                <tr class="border-b last:border-0">
                                    <td class="py-2 pe-4">{{ $row->content }}</td>
                                    <td class="py-2">{{ \App\Support\Numbers::format($row->cnt) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
