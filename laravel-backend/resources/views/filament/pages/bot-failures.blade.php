@php
    $summary = $this->summary();
    $rows = $this->rows();
    $baselines = $this->baselines();
    $kinds = ['empty', 'fallback', 'misunderstood'];
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('bot_failures.intro') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-filament::button size="sm" :color="$kindFilter === null ? 'primary' : 'gray'" wire:click="$set('kindFilter', null)">
                    {{ __('bot_failures.all') }} ({{ \App\Support\Numbers::format(array_sum($summary)) }})
                </x-filament::button>
                @foreach ($kinds as $k)
                    <x-filament::button size="sm" :color="$kindFilter === $k ? 'primary' : 'gray'" wire:click="$set('kindFilter', '{{ $k }}')">
                        {{ __('bot_failures.kind_' . $k) }} ({{ \App\Support\Numbers::format($summary[$k] ?? 0) }})
                    </x-filament::button>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('bot_failures.recent', ['days' => \App\Support\Numbers::format($days)])">
            @if (empty($rows))
                <p class="text-sm text-gray-500">{{ __('bot_failures.none') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-gray-500">
                                <th class="py-2 pe-4 text-start">{{ __('bot_failures.col_when') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('bot_failures.col_chatbot') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('bot_failures.col_kind') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('bot_failures.col_question') }}</th>
                                <th class="py-2 text-start">{{ __('bot_failures.col_reply') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr class="border-b align-top last:border-0">
                                    <td class="py-2 pe-4 whitespace-nowrap">{{ \App\Support\Jalali::dateTime($r->occurred_at) }}@if ($r->source === 'backfill') <span class="text-xs text-gray-400">· {{ __('bot_failures.backfill') }}</span>@endif</td>
                                    <td class="py-2 pe-4">{{ $r->chatbot ?? '—' }} <span class="text-xs text-gray-400">{{ $r->tenant }}</span></td>
                                    <td class="py-2 pe-4">{{ __('bot_failures.kind_' . $r->kind) }}</td>
                                    <td class="py-2 pe-4" dir="auto">{{ \Illuminate\Support\Str::limit($r->question ?? '—', 160) }}</td>
                                    <td class="py-2" dir="auto">{{ $r->reply !== null && trim($r->reply) !== '' ? \Illuminate\Support\Str::limit($r->reply, 160) : __('bot_failures.empty_reply') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('bot_failures.baselines')">
            @if (empty($baselines))
                <p class="text-sm text-gray-500">{{ __('bot_failures.no_baselines') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-gray-500">
                                <th class="py-2 pe-4 text-start">{{ __('bot_failures.col_chatbot') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('bot_failures.col_period') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('bot_failures.col_replies') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('bot_failures.col_failed') }}</th>
                                <th class="py-2 text-start">{{ __('bot_failures.col_by_kind') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($baselines as $b)
                                @php $byKind = json_decode((string) $b->by_kind, true) ?: []; @endphp
                                <tr class="border-b last:border-0">
                                    <td class="py-2 pe-4">{{ $b->chatbot_name }}</td>
                                    <td class="py-2 pe-4 whitespace-nowrap">{{ \App\Support\Jalali::date($b->period_from) }} – {{ \App\Support\Jalali::date($b->period_to) }}</td>
                                    <td class="py-2 pe-4">{{ \App\Support\Numbers::format($b->replies) }}</td>
                                    <td class="py-2 pe-4">{{ \App\Support\Numbers::format($b->failed) }} ({{ \App\Support\Numbers::format((float) $b->failed_pct, 1) }}%)</td>
                                    <td class="py-2">
                                        @foreach ($byKind as $k => $n)
                                            {{ __('bot_failures.kind_' . $k) }}: {{ \App\Support\Numbers::format($n) }}@if (!$loop->last) · @endif
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
