{{-- The report body, shared by the portal page. Each section states
     explicitly when it has nothing, rather than rendering an empty table. --}}

<x-filament::section>
    <x-slot name="heading">{{ __('trends.top_intents') }}</x-slot>
    <x-slot name="description">{{ __('trends.top_intents_desc') }}</x-slot>
    @if (empty($data['intents']))
        <p class="text-sm text-gray-500">{{ __('trends.empty_section') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-gray-500 border-b">
                        <th class="py-2 pe-4 text-start">{{ __('trends.col_intent') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('trends.col_count') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('trends.col_share') }}</th>
                        <th class="py-2 text-start">{{ __('trends.col_change') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['intents'] as $row)
                        <tr class="border-b last:border-0">
                            <td class="py-2 pe-4">{{ $page->intentLabel($row['intent']) }}</td>
                            <td class="py-2 pe-4">{{ number_format($row['count']) }}</td>
                            <td class="py-2 pe-4">{{ $row['share_pct'] }}%</td>
                            <td @class([
                                'py-2',
                                'text-success-600' => $row['change_pct'] !== null && $row['change_pct'] > 0,
                                'text-danger-600' => $row['change_pct'] !== null && $row['change_pct'] < 0,
                                'text-gray-500' => $row['change_pct'] === null,
                            ])>{{ $page->changeText($row['change_pct']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>

<div class="grid gap-6 md:grid-cols-2">
    <x-filament::section>
        <x-slot name="heading">{{ __('trends.asked_products') }}</x-slot>
        <x-slot name="description">{{ __('trends.asked_products_desc') }}</x-slot>
        @if (empty($data['asked_products']))
            <p class="text-sm text-gray-500">{{ __('trends.empty_section') }}</p>
        @else
            <ul class="text-sm divide-y">
                @foreach ($data['asked_products'] as $row)
                    <li class="py-2 flex justify-between gap-4">
                        <span>{{ $row['title'] }}</span>
                        <span class="text-gray-500">{{ number_format($row['count']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('trends.best_sellers') }}</x-slot>
        <x-slot name="description">{{ __('trends.best_sellers_desc') }}</x-slot>
        @if (empty($data['best_sellers']))
            <p class="text-sm text-gray-500">{{ __('trends.empty_section') }}</p>
        @else
            <ul class="text-sm divide-y">
                @foreach ($data['best_sellers'] as $row)
                    <li class="py-2 flex justify-between gap-4">
                        <span>{{ $row['title'] }}</span>
                        <span class="text-gray-500">{{ number_format($row['count']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</div>

<x-filament::section>
    <x-slot name="heading">{{ __('trends.demand_gap') }}</x-slot>
    <x-slot name="description">{{ __('trends.demand_gap_desc') }}</x-slot>
    @if (empty($data['demand_gap']))
        <p class="text-sm text-gray-500">{{ __('trends.empty_section') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-gray-500 border-b">
                        <th class="py-2 pe-4 text-start">{{ __('trends.col_product') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('trends.col_ask_rank') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('trends.col_sell_rank') }}</th>
                        <th class="py-2 text-start">{{ __('trends.col_count') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['demand_gap'] as $row)
                        <tr class="border-b last:border-0">
                            <td class="py-2 pe-4">{{ $row['title'] }}</td>
                            <td class="py-2 pe-4">{{ $row['ask_rank'] }}</td>
                            <td class="py-2 pe-4">
                                {{ $row['sell_rank'] ?? __('trends.not_sold') }}
                            </td>
                            <td class="py-2">{{ number_format($row['asked']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>

<x-filament::section>
    <x-slot name="heading">{{ __('trends.missing_from_catalog') }}</x-slot>
    <x-slot name="description">{{ __('trends.missing_from_catalog_desc') }}</x-slot>
    @if (empty($data['missing_from_catalog']))
        <p class="text-sm text-gray-500">{{ __('trends.empty_section') }}</p>
    @else
        <ul class="text-sm divide-y">
            @foreach ($data['missing_from_catalog'] as $row)
                <li class="py-2 flex justify-between gap-4">
                    <span>{{ $row['label'] }}</span>
                    <span class="text-gray-500">{{ number_format($row['count']) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>

<div class="grid gap-6 md:grid-cols-2">
    <x-filament::section>
        <x-slot name="heading">{{ __('trends.emerging') }}</x-slot>
        <x-slot name="description">{{ __('trends.emerging_desc') }}</x-slot>
        @if (empty($data['emerging_topics']))
            <p class="text-sm text-gray-500">{{ __('trends.empty_section') }}</p>
        @else
            <ul class="text-sm divide-y">
                @foreach ($data['emerging_topics'] as $row)
                    <li class="py-2 flex justify-between gap-4">
                        <span>{{ $row['label'] }}</span>
                        <span class="text-success-600">{{ __('trends.change_new') }} · {{ number_format($row['count']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('trends.declining') }}</x-slot>
        <x-slot name="description">{{ __('trends.declining_desc') }}</x-slot>
        @if (empty($data['declining_topics']))
            <p class="text-sm text-gray-500">{{ __('trends.empty_section') }}</p>
        @else
            <ul class="text-sm divide-y">
                @foreach ($data['declining_topics'] as $row)
                    <li class="py-2 flex justify-between gap-4">
                        <span>{{ $row['label'] }}</span>
                        <span class="text-danger-600" dir="ltr">
                            {{ number_format($row['prev_count']) }} → {{ number_format($row['count']) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</div>

<x-filament::section>
    <x-slot name="heading">{{ __('trends.unanswered') }}</x-slot>
    <x-slot name="description">{{ __('trends.unanswered_desc') }}</x-slot>
    @if (empty($data['unanswered_groups']))
        <p class="text-sm text-gray-500">{{ __('trends.empty_section') }}</p>
    @else
        <ul class="text-sm divide-y">
            @foreach ($data['unanswered_groups'] as $row)
                <li class="py-2">
                    <div class="flex justify-between gap-4">
                        <span>{{ $row['label'] }}</span>
                        <span class="text-gray-500">{{ number_format($row['count']) }}</span>
                    </div>
                    @if (count($row['variants']) > 1)
                        <div class="mt-1 text-xs text-gray-400">
                            {{ __('trends.variants_label') }}
                            {{ implode(' / ', array_slice($row['variants'], 1, 3)) }}
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
