{{-- Standalone print view: this is the report's "PDF". The browser's own
     engine handles Persian shaping and RTL correctly, which is exactly what
     a server-side PDF library would get wrong. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('trends.csv_report_title') }} — {{ $tenantName }}</title>
    <style>
        :root { color-scheme: light; }
        body {
            font-family: 'Vazirmatn', 'IRANSans', 'Segoe UI', Tahoma, sans-serif;
            color: #0F172A; background: #fff;
            margin: 0; padding: 32px; font-size: 13px; line-height: 1.7;
        }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 24px 0 4px; padding-bottom: 4px; border-bottom: 2px solid #E2E8F0; }
        .sub { color: #64748B; font-size: 12px; margin: 0 0 2px; }
        .meta { color: #64748B; font-size: 12px; margin-bottom: 20px; }
        .headline { font-size: 15px; margin: 12px 0 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { text-align: start; padding: 6px 8px; border-bottom: 1px solid #E2E8F0; }
        th { color: #64748B; font-weight: 600; font-size: 12px; }
        .num { color: #64748B; }
        .up { color: #15803D; }
        .down { color: #B91C1C; }
        .empty { color: #94A3B8; font-size: 12px; margin-top: 6px; }
        .notice { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px 16px; margin-top: 12px; }
        .ltr { direction: ltr; display: inline-block; }
        .print-hint { margin-top: 28px; color: #94A3B8; font-size: 11px; }
        @media print {
            body { padding: 0; }
            .print-hint { display: none; }
            h2 { break-after: avoid; }
            table { break-inside: auto; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>

<h1>{{ __('trends.csv_report_title') }}</h1>
<p class="sub">{{ $tenantName }}</p>
<p class="meta">
    <span class="ltr">{{ __('trends.range_summary', ['from' => $data['range_start'], 'to' => $data['range_end']]) }}</span>
    &nbsp;·&nbsp;
    <span class="ltr">{{ __('trends.compared_to', ['from' => $data['prev_start'], 'to' => $data['prev_end']]) }}</span>
</p>

<p class="headline">
    {{ __('trends.questions_total') }}: <strong>{{ number_format($data['user_messages']) }}</strong>
</p>

@if (! $data['has_enough_data'])
    <div class="notice">
        <strong>{{ __('trends.low_data_title') }}</strong>
        <div class="empty">
            {{ __('trends.low_data_body', ['count' => number_format($data['user_messages']), 'min' => $data['min_messages']]) }}
        </div>
    </div>
@else

    <h2>{{ __('trends.top_intents') }}</h2>
    @if (empty($data['intents']))
        <p class="empty">{{ __('trends.empty_section') }}</p>
    @else
        <table>
            <tr>
                <th>{{ __('trends.col_intent') }}</th><th>{{ __('trends.col_count') }}</th>
                <th>{{ __('trends.col_share') }}</th><th>{{ __('trends.col_change') }}</th>
            </tr>
            @foreach ($data['intents'] as $row)
                <tr>
                    <td>{{ __('trends.intent_' . $row['intent']) === 'trends.intent_' . $row['intent'] ? $row['intent'] : __('trends.intent_' . $row['intent']) }}</td>
                    <td class="num">{{ number_format($row['count']) }}</td>
                    <td class="num">{{ $row['share_pct'] }}%</td>
                    <td class="{{ $row['change_pct'] === null ? 'num' : ($row['change_pct'] >= 0 ? 'up' : 'down') }}">
                        {{ $row['change_pct'] === null ? __('trends.change_new') : (($row['change_pct'] > 0 ? '+' : '') . $row['change_pct'] . '%') }}
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('trends.asked_products') }}</h2>
    @if (empty($data['asked_products']))
        <p class="empty">{{ __('trends.empty_section') }}</p>
    @else
        <table>
            <tr><th>{{ __('trends.col_product') }}</th><th>{{ __('trends.col_count') }}</th></tr>
            @foreach ($data['asked_products'] as $row)
                <tr><td>{{ $row['title'] }}</td><td class="num">{{ number_format($row['count']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('trends.best_sellers') }}</h2>
    @if (empty($data['best_sellers']))
        <p class="empty">{{ __('trends.empty_section') }}</p>
    @else
        <table>
            <tr><th>{{ __('trends.col_product') }}</th><th>{{ __('trends.col_sold') }}</th></tr>
            @foreach ($data['best_sellers'] as $row)
                <tr><td>{{ $row['title'] }}</td><td class="num">{{ number_format($row['count']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('trends.demand_gap') }}</h2>
    <p class="sub">{{ __('trends.demand_gap_desc') }}</p>
    @if (empty($data['demand_gap']))
        <p class="empty">{{ __('trends.empty_section') }}</p>
    @else
        <table>
            <tr>
                <th>{{ __('trends.col_product') }}</th><th>{{ __('trends.col_ask_rank') }}</th>
                <th>{{ __('trends.col_sell_rank') }}</th><th>{{ __('trends.col_count') }}</th>
            </tr>
            @foreach ($data['demand_gap'] as $row)
                <tr>
                    <td>{{ $row['title'] }}</td>
                    <td class="num">{{ $row['ask_rank'] }}</td>
                    <td class="num">{{ $row['sell_rank'] ?? __('trends.not_sold') }}</td>
                    <td class="num">{{ number_format($row['asked']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('trends.compared') }}</h2>
    <p class="sub">{{ __('trends.compared_desc') }}</p>
    @if (empty($data['compared_pairs']))
        <p class="empty">{{ __('trends.empty_section') }}</p>
    @else
        <table>
            <tr>
                <th>{{ __('trends.col_pair') }}</th><th>{{ __('trends.col_times') }}</th>
                <th>{{ __('trends.col_winner') }}</th>
            </tr>
            @foreach ($data['compared_pairs'] as $row)
                <tr>
                    <td>{{ $row['names'][0] }} {{ __('trends.versus') }} {{ $row['names'][1] }}</td>
                    <td class="num">{{ number_format($row['count']) }}</td>
                    <td class="{{ $row['winner'] === null || $row['winner'] === 'tie' ? 'num' : 'up' }}">
                        {{ $row['winner'] === null ? __('trends.no_winner_yet') : ($row['winner'] === 'tie' ? __('trends.tie') : $row['names'][$row['winner']]) }}
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('trends.missing_from_catalog') }}</h2>
    <p class="sub">{{ __('trends.missing_from_catalog_desc') }}</p>
    @if (empty($data['missing_from_catalog']))
        <p class="empty">{{ __('trends.empty_section') }}</p>
    @else
        <table>
            <tr><th>{{ __('trends.col_request') }}</th><th>{{ __('trends.col_count') }}</th></tr>
            @foreach ($data['missing_from_catalog'] as $row)
                <tr><td>{{ $row['label'] }}</td><td class="num">{{ number_format($row['count']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('trends.emerging') }}</h2>
    @if (empty($data['emerging_topics']))
        <p class="empty">{{ __('trends.empty_section') }}</p>
    @else
        <table>
            <tr><th>{{ __('trends.col_topic') }}</th><th>{{ __('trends.col_count') }}</th></tr>
            @foreach ($data['emerging_topics'] as $row)
                <tr><td>{{ $row['label'] }}</td><td class="up">{{ number_format($row['count']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('trends.declining') }}</h2>
    @if (empty($data['declining_topics']))
        <p class="empty">{{ __('trends.empty_section') }}</p>
    @else
        <table>
            <tr>
                <th>{{ __('trends.col_topic') }}</th><th>{{ __('trends.col_prev_count') }}</th>
                <th>{{ __('trends.col_count') }}</th>
            </tr>
            @foreach ($data['declining_topics'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ number_format($row['prev_count']) }}</td>
                    <td class="down">{{ number_format($row['count']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('trends.unanswered') }}</h2>
    <p class="sub">{{ __('trends.unanswered_desc') }}</p>
    @if (empty($data['unanswered_groups']))
        <p class="empty">{{ __('trends.empty_section') }}</p>
    @else
        <table>
            <tr><th>{{ __('trends.col_question') }}</th><th>{{ __('trends.col_count') }}</th></tr>
            @foreach ($data['unanswered_groups'] as $row)
                <tr><td>{{ $row['label'] }}</td><td class="num">{{ number_format($row['count']) }}</td></tr>
            @endforeach
        </table>
    @endif

@endif

<h2>{{ __('trends.seasonal') }}</h2>
@if (! $data['seasonal']['available'])
    <p class="empty">{{ __('trends.seasonal_unavailable') }}</p>
    <p class="empty">{{ __('trends.seasonal_progress', ['days' => number_format($data['history_days'])]) }}</p>
@else
    <table>
        <tr>
            <th>{{ __('trends.seasonal_this_month') }}</th>
            <th>{{ __('trends.seasonal_last_year') }}</th>
            <th>{{ __('trends.col_change') }}</th>
        </tr>
        <tr>
            <td>{{ number_format($data['seasonal']['current']) }}</td>
            <td>{{ number_format($data['seasonal']['last_year']) }}</td>
            <td class="{{ ($data['seasonal']['change_pct'] ?? 0) >= 0 ? 'up' : 'down' }}">
                {{ $data['seasonal']['change_pct'] === null ? __('trends.change_new') : $data['seasonal']['change_pct'] . '%' }}
            </td>
        </tr>
    </table>
@endif

<p class="meta" style="margin-top:24px">
    {{ __('trends.generated_at', ['time' => \App\Support\Jalali::dateTime($data['generated_at'])]) }}
</p>
<p class="print-hint">{{ __('trends.print_hint') }}</p>

</body>
</html>
