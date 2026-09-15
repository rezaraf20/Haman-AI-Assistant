<?php
namespace App\Filament\Customer\Pages;

use App\Services\TrendsService;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the merchant cannot see from their own shop admin: the questions
 * behind the sales. Rising topics, dying ones, products people keep asking
 * about that do not sell, and things asked for by name that the shop does
 * not stock.
 *
 * Every number comes from TrendsService, which reads pre-aggregated
 * analytics_daily rows and grouped conversation_events — never a live scan
 * of the messages table, which is what would make a one-year range
 * unusable on a busy tenant. See TrendsPageTest for the query-count guard.
 */
class Trends extends Page
{
    protected static string $view = 'filament.customer.pages.trends';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    /** Bound to the range selector; only the known keys are ever honoured. */
    public string $range = '30d';

    public static function getNavigationLabel(): string { return __('trends.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('trends.nav'); }

    public function mount(): void
    {
        $this->range = $this->sanitizeRange(request()->query('range', '30d'));
    }

    private function sanitizeRange(?string $range): string
    {
        return array_key_exists((string) $range, TrendsService::RANGES) ? (string) $range : '30d';
    }

    public function setRange(string $range): void
    {
        $this->range = $this->sanitizeRange($range);
    }

    public function getRangeOptions(): array
    {
        return [
            '30d' => __('trends.range_30d'),
            '3m'  => __('trends.range_3m'),
            '6m'  => __('trends.range_6m'),
            '1y'  => __('trends.range_1y'),
        ];
    }

    public function getData(): array
    {
        $schema = auth()->user()->tenant->schema_name;
        return app(TrendsService::class)->get($schema, $this->sanitizeRange($this->range));
    }

    public function intentLabel(string $intent): string
    {
        $key = 'trends.intent_' . $intent;
        $label = __($key);
        // __() hands back the key itself for an unknown intent, so a new
        // classifier label shows as its raw name rather than "trends.intent_x".
        return $label === $key ? $intent : $label;
    }

    /**
     * CSV for the buying team's spreadsheet. Sectioned rather than one flat
     * table: the report genuinely is several different lists, and flattening
     * them into shared columns would make it unreadable in Excel.
     */
    public function downloadCsv(): StreamedResponse
    {
        $data = $this->getData();
        $range = $this->sanitizeRange($this->range);
        $filename = 'haman-trends-' . $range . '-' . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            // Excel opens UTF-8 as mojibake without a BOM, and this report
            // is mostly Persian.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [__('trends.csv_report_title')]);
            fputcsv($out, [__('trends.csv_range'), $data['range_start'] . ' .. ' . $data['range_end']]);
            fputcsv($out, [__('trends.csv_compared_to'), $data['prev_start'] . ' .. ' . $data['prev_end']]);
            fputcsv($out, [__('trends.questions_total'), $data['user_messages']]);
            fputcsv($out, []);

            fputcsv($out, [__('trends.top_intents')]);
            fputcsv($out, [__('trends.col_intent'), __('trends.col_count'), __('trends.col_share'), __('trends.col_change')]);
            foreach ($data['intents'] as $r) {
                fputcsv($out, [$this->intentLabel($r['intent']), $r['count'], $r['share_pct'] . '%', $this->changeText($r['change_pct'])]);
            }
            fputcsv($out, []);

            fputcsv($out, [__('trends.asked_products')]);
            fputcsv($out, [__('trends.col_product'), __('trends.col_count')]);
            foreach ($data['asked_products'] as $r) fputcsv($out, [$r['title'], $r['count']]);
            fputcsv($out, []);

            fputcsv($out, [__('trends.best_sellers')]);
            fputcsv($out, [__('trends.col_product'), __('trends.col_sold')]);
            foreach ($data['best_sellers'] as $r) fputcsv($out, [$r['title'], $r['count']]);
            fputcsv($out, []);

            fputcsv($out, [__('trends.demand_gap')]);
            fputcsv($out, [__('trends.col_product'), __('trends.col_ask_rank'), __('trends.col_sell_rank'), __('trends.col_count')]);
            foreach ($data['demand_gap'] as $r) {
                fputcsv($out, [$r['title'], $r['ask_rank'], $r['sell_rank'] ?? __('trends.not_sold'), $r['asked']]);
            }
            fputcsv($out, []);

            fputcsv($out, [__('trends.compared')]);
            fputcsv($out, [__('trends.col_pair'), __('trends.col_times'), __('trends.col_winner')]);
            foreach ($data['compared_pairs'] as $r) {
                $winner = $r['winner'] === null ? __('trends.no_winner_yet')
                    : ($r['winner'] === 'tie' ? __('trends.tie') : $r['names'][$r['winner']]);
                fputcsv($out, [$r['names'][0] . ' / ' . $r['names'][1], $r['count'], $winner]);
            }
            fputcsv($out, []);

            fputcsv($out, [__('trends.missing_from_catalog')]);
            fputcsv($out, [__('trends.col_request'), __('trends.col_count')]);
            foreach ($data['missing_from_catalog'] as $r) fputcsv($out, [$r['label'], $r['count']]);
            fputcsv($out, []);

            fputcsv($out, [__('trends.emerging')]);
            fputcsv($out, [__('trends.col_topic'), __('trends.col_count')]);
            foreach ($data['emerging_topics'] as $r) fputcsv($out, [$r['label'], $r['count']]);
            fputcsv($out, []);

            fputcsv($out, [__('trends.declining')]);
            fputcsv($out, [__('trends.col_topic'), __('trends.col_prev_count'), __('trends.col_count')]);
            foreach ($data['declining_topics'] as $r) fputcsv($out, [$r['label'], $r['prev_count'], $r['count']]);
            fputcsv($out, []);

            fputcsv($out, [__('trends.unanswered')]);
            fputcsv($out, [__('trends.col_question'), __('trends.col_count')]);
            foreach ($data['unanswered_groups'] as $r) fputcsv($out, [$r['label'], $r['count']]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function changeText(?float $pct): string
    {
        if ($pct === null) return __('trends.change_new');
        return ($pct > 0 ? '+' : '') . $pct . '%';
    }
}
