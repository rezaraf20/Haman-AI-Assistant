<?php
namespace App\Services;

use App\Support\TextSimilarity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The "Trends" report: what the merchant cannot see from their own admin —
 * what people asked for, what they asked for and could not get, what is
 * rising, what is dying, and where demand and sales disagree.
 *
 * Everything here reads pre-aggregated analytics_daily rows plus grouped
 * conversation_events. It deliberately never scans the messages table:
 * that is the one query on this page that would grow without bound as a
 * busy tenant accumulates history, and this page offers a one-year range.
 *
 * Query budget is a hard requirement (under 15 for the whole page), so the
 * shape here is "a handful of grouped queries covering both the current
 * and the previous period at once", not per-panel queries. See
 * TrendsServiceTest, which asserts the count rather than trusting this
 * comment to stay true.
 */
class TrendsService
{
    public const RANGES = ['30d' => 30, '3m' => 90, '6m' => 180, '1y' => 365];

    /** Below this many user messages in the window, a panel's numbers are
     *  noise dressed as insight — the page says so instead of drawing a
     *  chart of three data points. */
    public const MIN_MESSAGES_FOR_INSIGHT = 30;

    private const CACHE_TTL_SECONDS = 1800;
    private const LONG_RANGE_CACHE_TTL_SECONDS = 21600; // 1y: expensive, and a day-old answer is fine

    public function rangeDays(string $range): int
    {
        return self::RANGES[$range] ?? 30;
    }

    public function get(string $schema, string $range): array
    {
        $days = $this->rangeDays($range);
        $ttl = $days >= 365 ? self::LONG_RANGE_CACHE_TTL_SECONDS : self::CACHE_TTL_SECONDS;
        // Keyed by day so a cached report can never outlive the data it
        // describes by more than the TTL, and never straddles midnight.
        $key = "trends:{$schema}:{$range}:" . now()->toDateString();

        return Cache::remember($key, $ttl, fn () => $this->build($schema, $days));
    }

    public function forget(string $schema, string $range): void
    {
        Cache::forget("trends:{$schema}:{$range}:" . now()->toDateString());
    }

    private function build(string $schema, int $days): array
    {
        $end = now()->endOfDay();
        $start = now()->subDays($days - 1)->startOfDay();
        $prevStart = (clone $start)->subDays($days);
        $prevEnd = (clone $start)->subSecond();

        DB::statement("SET search_path TO {$schema}, public");
        try {
            $daily = $this->dailyRows($prevStart, $end);                 // 1
            $events = $this->eventRows($prevStart, $end);                // 2
            $orders = $this->orderRows($start, $end);                    // 3
            $catalog = $this->catalogIndex();                            // 4
            $history = $this->historySpan();                             // 5
            $seasonal = $this->seasonal($history);                       // 6 (or 0)
        } finally {
            DB::statement('SET search_path TO public');
        }

        [$cur, $prev] = $this->splitByPeriod($daily, $start);
        [$curEvents, $prevEvents] = $this->splitEventsByPeriod($events, $start);

        $curMessages = array_sum(array_column($cur, 'user_messages'));
        $prevMessages = array_sum(array_column($prev, 'user_messages'));

        $askedProducts = $this->askedProducts($curEvents, $catalog);
        $bestSellers = $this->bestSellers($orders, $catalog);

        return [
            'range_start'      => $start->toDateString(),
            'range_end'        => $end->toDateString(),
            'prev_start'       => $prevStart->toDateString(),
            'prev_end'         => $prevEnd->toDateString(),
            'user_messages'    => $curMessages,
            'prev_user_messages' => $prevMessages,
            'messages_change'  => $this->pctChange($curMessages, $prevMessages),
            'has_enough_data'  => $curMessages >= self::MIN_MESSAGES_FOR_INSIGHT,
            'min_messages'     => self::MIN_MESSAGES_FOR_INSIGHT,
            'intents'          => $this->intents($cur, $prev),
            'asked_products'   => $askedProducts,
            'best_sellers'     => $bestSellers,
            'demand_gap'       => $this->demandGap($askedProducts, $bestSellers),
            'missing_from_catalog' => $this->missingFromCatalog($curEvents),
            'emerging_topics'  => $this->emergingTopics($curEvents, $prevEvents),
            'declining_topics' => $this->decliningTopics($curEvents, $prevEvents),
            'unanswered_groups' => $this->unansweredGroups($curEvents),
            'history_days'     => $history['days'],
            'seasonal'         => $seasonal,
            'generated_at'     => now()->toDateTimeString(),
        ];
    }

    // ── Raw reads ───────────────────────────────────────────────────────

    /** One row per chatbot per day, covering BOTH periods in a single pass. */
    private function dailyRows(Carbon $from, Carbon $to): array
    {
        return DB::table('analytics_daily')
            ->selectRaw('date, SUM(user_messages) AS user_messages, SUM(unanswered_count) AS unanswered_count,
                         jsonb_agg(intent_counts) AS intents')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Only the event types this report reads, already grouped by
     * (type, day, payload) so a chatty tenant does not stream a million
     * rows into PHP just to have them counted here.
     */
    private function eventRows(Carbon $from, Carbon $to): array
    {
        return DB::table('conversation_events')
            ->selectRaw("event_type, created_at::date AS day, payload, COUNT(*) AS cnt")
            ->whereIn('event_type', ['product_mentioned', 'unanswered', 'sku_lookup'])
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('event_type', DB::raw('created_at::date'), 'payload')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** Sales for the current window. Every checkout order is recorded here
     *  (Hamman_Sync_Manager::on_order_placed hooks woocommerce_thankyou for
     *  all orders, not only bot-created ones), so this is real sales data,
     *  not just what the bot influenced. */
    private function orderRows(Carbon $from, Carbon $to): array
    {
        return DB::table('orders')
            ->select('line_items')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'failed', 'refunded'])
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** external_id => title for indexed products, used to turn both
     *  permalinks and numeric order line items into names a human reads. */
    private function catalogIndex(): array
    {
        $rows = DB::table('documents')
            ->select('external_id', 'title')
            ->where('source_type', 'woocommerce_product')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            if ($r->external_id !== null && $r->external_id !== '') {
                $out[(string) $r->external_id] = (string) $r->title;
            }
        }
        return $out;
    }

    private function historySpan(): array
    {
        $row = DB::table('analytics_daily')
            ->selectRaw('MIN(date) AS first_date, MAX(date) AS last_date')
            ->first();

        if (!$row || !$row->first_date) return ['days' => 0, 'first_date' => null];

        // diffInDays() returns a float on this Carbon version, and this
        // value is rendered straight into "N days of data collected".
        return [
            'days' => (int) floor(Carbon::parse($row->first_date)->diffInDays(now())) + 1,
            'first_date' => $row->first_date,
        ];
    }

    /**
     * This month versus the same month last year. Only attempted when a
     * full year of history actually exists — the alternative is a chart
     * comparing real numbers against a year of zeroes, which reads as "we
     * collapsed" rather than "we weren't here yet".
     */
    private function seasonal(array $history): array
    {
        if ($history['days'] < 365) {
            return ['available' => false, 'history_days' => $history['days']];
        }

        $thisStart = now()->startOfMonth();
        $thisEnd = now()->endOfMonth();
        $lastStart = (clone $thisStart)->subYear();
        $lastEnd = (clone $thisStart)->subYear()->endOfMonth();

        $rows = DB::table('analytics_daily')
            ->selectRaw("CASE WHEN date >= ? THEN 'current' ELSE 'last_year' END AS bucket,
                         SUM(user_messages) AS user_messages, SUM(unanswered_count) AS unanswered_count",
                [$thisStart->toDateString()])
            ->where(function ($q) use ($thisStart, $thisEnd, $lastStart, $lastEnd) {
                $q->whereBetween('date', [$thisStart->toDateString(), $thisEnd->toDateString()])
                  ->orWhereBetween('date', [$lastStart->toDateString(), $lastEnd->toDateString()]);
            })
            ->groupBy('bucket')
            ->get();

        $current = 0;
        $lastYear = 0;
        foreach ($rows as $r) {
            if ($r->bucket === 'current') $current = (int) $r->user_messages;
            else $lastYear = (int) $r->user_messages;
        }

        return [
            'available'   => true,
            'month'       => $thisStart->toDateString(),
            'current'     => $current,
            'last_year'   => $lastYear,
            'change_pct'  => $this->pctChange($current, $lastYear),
        ];
    }

    // ── Shaping ─────────────────────────────────────────────────────────

    private function splitByPeriod(array $daily, Carbon $start): array
    {
        $cur = $prev = [];
        $boundary = $start->toDateString();
        foreach ($daily as $row) {
            if ((string) $row['date'] >= $boundary) $cur[] = $row;
            else $prev[] = $row;
        }
        return [$cur, $prev];
    }

    private function splitEventsByPeriod(array $events, Carbon $start): array
    {
        $cur = $prev = [];
        $boundary = $start->toDateString();
        foreach ($events as $row) {
            if ((string) $row['day'] >= $boundary) $cur[] = $row;
            else $prev[] = $row;
        }
        return [$cur, $prev];
    }

    public function pctChange(float $current, float $previous): ?float
    {
        // No previous baseline means "new", not "up 100%" — the page shows
        // those as new rather than inventing a percentage.
        if ($previous <= 0) return null;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    /** Top intents now, each with its change against the previous period. */
    private function intents(array $cur, array $prev): array
    {
        $curCounts = $this->sumIntents($cur);
        $prevCounts = $this->sumIntents($prev);
        arsort($curCounts);

        $total = array_sum($curCounts) ?: 1;
        $out = [];
        foreach (array_slice($curCounts, 0, 10, true) as $intent => $count) {
            $out[] = [
                'intent'     => $intent,
                'count'      => $count,
                'share_pct'  => round($count / $total * 100, 1),
                'prev_count' => $prevCounts[$intent] ?? 0,
                'change_pct' => $this->pctChange($count, $prevCounts[$intent] ?? 0),
            ];
        }
        return $out;
    }

    private function sumIntents(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $blobs = json_decode((string) ($row['intents'] ?? '[]'), true) ?: [];
            foreach ($blobs as $blob) {
                if (!is_array($blob)) continue;
                foreach ($blob as $intent => $count) {
                    $out[$intent] = ($out[$intent] ?? 0) + (int) $count;
                }
            }
        }
        return $out;
    }

    /**
     * What people asked about. product_mentioned carries permalinks and
     * titles rather than numeric ids, so the trailing id is parsed out of
     * the permalink to line up with order line items — that join is the
     * only way the demand-vs-sales gap below can exist at all.
     */
    private function askedProducts(array $events, array $catalog): array
    {
        $counts = [];
        foreach ($events as $row) {
            if ($row['event_type'] !== 'product_mentioned') continue;
            $payload = json_decode((string) $row['payload'], true) ?: [];
            $ids = $payload['product_ids'] ?? [];
            $titles = $payload['context'] ?? [];
            $cnt = (int) $row['cnt'];

            foreach ($ids as $i => $ref) {
                $id = $this->productIdFromRef((string) $ref);
                $title = $titles[$i] ?? ($id !== null ? ($catalog[$id] ?? null) : null);
                $key = $id ?? (string) $ref;
                if (!isset($counts[$key])) {
                    $counts[$key] = ['product_id' => $id, 'title' => $title ?: (string) $ref, 'count' => 0];
                }
                $counts[$key]['count'] += $cnt;
            }
        }

        usort($counts, fn ($a, $b) => $b['count'] <=> $a['count']);
        return array_slice(array_values($counts), 0, 15);
    }

    /** ".../product/1969" or a bare "1969" -> "1969"; anything else null. */
    private function productIdFromRef(string $ref): ?string
    {
        if (preg_match('/(\d+)\s*$/', rtrim($ref, '/'), $m)) return $m[1];
        return null;
    }

    private function bestSellers(array $orders, array $catalog): array
    {
        $counts = [];
        foreach ($orders as $order) {
            $items = json_decode((string) $order['line_items'], true) ?: [];
            foreach ($items as $item) {
                $id = isset($item['product_id']) ? (string) $item['product_id'] : null;
                if ($id === null) continue;
                if (!isset($counts[$id])) {
                    $counts[$id] = ['product_id' => $id, 'title' => $catalog[$id] ?? $id, 'count' => 0];
                }
                $counts[$id]['count'] += (int) ($item['quantity'] ?? 1);
            }
        }
        usort($counts, fn ($a, $b) => $b['count'] <=> $a['count']);
        return array_slice(array_values($counts), 0, 15);
    }

    /**
     * The insight the merchant cannot get anywhere else: products people
     * keep asking about that are NOT selling in proportion. A high ask
     * rank with a poor sales rank usually means the page, price or stock
     * is the problem — the interest already exists.
     */
    private function demandGap(array $asked, array $sold): array
    {
        if (!$asked || !$sold) return [];

        $soldRank = [];
        foreach ($sold as $i => $s) {
            if ($s['product_id'] !== null) $soldRank[$s['product_id']] = $i + 1;
        }

        $out = [];
        foreach ($asked as $i => $a) {
            if ($a['product_id'] === null) continue;
            $askRank = $i + 1;
            $sellRank = $soldRank[$a['product_id']] ?? null;
            // Either absent from the sales list entirely, or markedly
            // further down it than it is up the "asked about" list.
            if ($sellRank === null || $sellRank - $askRank >= 3) {
                $out[] = [
                    'title'      => $a['title'],
                    'product_id' => $a['product_id'],
                    'ask_rank'   => $askRank,
                    'sell_rank'  => $sellRank,
                    'asked'      => $a['count'],
                ];
            }
        }
        return array_slice($out, 0, 10);
    }

    /**
     * Asked for by name and genuinely not in the catalog — the shopping
     * list for the buying team. Built from SKU lookups that matched
     * nothing, which is the one signal in this system that names a
     * specific product the shop does not carry.
     */
    private function missingFromCatalog(array $events): array
    {
        $counts = [];
        foreach ($events as $row) {
            if ($row['event_type'] !== 'sku_lookup') continue;
            $payload = json_decode((string) $row['payload'], true) ?: [];
            if (($payload['match_type'] ?? '') !== 'none') continue;
            $query = trim((string) ($payload['query'] ?? ''));
            if ($query === '') continue;
            $counts[$query] = ($counts[$query] ?? 0) + (int) $row['cnt'];
        }

        $items = [];
        foreach ($counts as $text => $count) $items[] = ['text' => $text, 'count' => $count];
        return TextSimilarity::group($items, 15);
    }

    private function topicCounts(array $events): array
    {
        $counts = [];
        foreach ($events as $row) {
            if ($row['event_type'] !== 'unanswered') continue;
            $payload = json_decode((string) $row['payload'], true) ?: [];
            $query = trim((string) ($payload['query'] ?? ''));
            if ($query === '') continue;
            $counts[$query] = ($counts[$query] ?? 0) + (int) $row['cnt'];
        }
        return $counts;
    }

    /** Grouped by similarity, not one row per exact string. */
    private function unansweredGroups(array $events): array
    {
        $items = [];
        foreach ($this->topicCounts($events) as $text => $count) {
            $items[] = ['text' => $text, 'count' => $count];
        }
        return TextSimilarity::group($items, 20);
    }

    /**
     * Topics present now and absent before. Compared on normalised token
     * sets rather than raw strings, so "ارسال به شیراز" and "ارسال شیراز"
     * are not reported as a brand-new topic every time someone rephrases.
     */
    private function emergingTopics(array $cur, array $prev): array
    {
        $curGroups = TextSimilarity::group($this->asItems($this->topicCounts($cur)), 50);
        $prevGroups = TextSimilarity::group($this->asItems($this->topicCounts($prev)), 50);
        $prevTokens = array_map(fn ($g) => TextSimilarity::tokens($g['label']), $prevGroups);

        $out = [];
        foreach ($curGroups as $g) {
            $tokens = TextSimilarity::tokens($g['label']);
            $seenBefore = false;
            foreach ($prevTokens as $pt) {
                if (TextSimilarity::jaccard($tokens, $pt) >= 0.5) { $seenBefore = true; break; }
            }
            if (!$seenBefore) $out[] = ['label' => $g['label'], 'count' => $g['count']];
        }
        return array_slice($out, 0, 10);
    }

    /** Was a real topic before, has faded or vanished now. */
    private function decliningTopics(array $cur, array $prev): array
    {
        $curGroups = TextSimilarity::group($this->asItems($this->topicCounts($cur)), 50);
        $prevGroups = TextSimilarity::group($this->asItems($this->topicCounts($prev)), 50);
        $curTokens = array_map(fn ($g) => TextSimilarity::tokens($g['label']), $curGroups);

        $out = [];
        foreach ($prevGroups as $g) {
            $tokens = TextSimilarity::tokens($g['label']);
            $now = 0;
            foreach ($curGroups as $i => $cg) {
                if (TextSimilarity::jaccard($tokens, $curTokens[$i]) >= 0.5) { $now = $cg['count']; break; }
            }
            if ($now < $g['count']) {
                $out[] = [
                    'label'      => $g['label'],
                    'prev_count' => $g['count'],
                    'count'      => $now,
                    'change_pct' => $this->pctChange($now, $g['count']),
                ];
            }
        }
        usort($out, fn ($a, $b) => ($b['prev_count'] - $b['count']) <=> ($a['prev_count'] - $a['count']));
        return array_slice($out, 0, 10);
    }

    private function asItems(array $counts): array
    {
        $items = [];
        foreach ($counts as $text => $count) $items[] = ['text' => $text, 'count' => $count];
        return $items;
    }
}
