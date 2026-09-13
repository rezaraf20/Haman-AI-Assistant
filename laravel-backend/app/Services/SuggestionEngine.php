<?php
namespace App\Services;

use App\Support\TextSimilarity;
use Illuminate\Support\Facades\DB;

/**
 * doc-07's "actions" item. The Trends report shows a merchant what
 * happened; this turns the same data into "do this next".
 *
 * Rules and thresholds only — never an LLM call. That is a requirement,
 * not a shortcut: a suggestion a merchant is expected to spend money
 * acting on has to be explainable ("31 people asked X, here are the
 * conversations"), reproducible, and free to compute. A model that
 * invents a plausible-sounding recommendation from the same data would be
 * neither auditable nor free, and would be wrong occasionally in ways
 * nobody could trace.
 *
 * Every rule below produces: a count, a stable fingerprint, and the real
 * conversation ids it came from. A suggestion without all three is not
 * shown, because the merchant could not check it.
 *
 * Runs from a daily job over a bounded window, not on page render — the
 * portal page only ever reads the suggestions table.
 */
class SuggestionEngine
{
    /** How far back a finding may draw on. */
    public const WINDOW_DAYS = 90;

    /** Never show more than this at once — a list of 40 "priorities" is
     *  not a priority list. */
    public const MAX_ACTIVE = 5;

    /** Per-rule minimum before a pattern is worth a merchant's attention.
     *  Deliberately different per rule: one person asking for a brand you
     *  don't stock is noise, but one unanswered question repeated by
     *  several people is not. */
    public const THRESHOLDS = [
        'repeated_question'     => 4,
        'unanswered_topic'      => 3,
        'missing_from_catalog'  => 3,
        'authenticity_doubt'    => 3,
        'compared_pair'         => 3,
        'asked_not_sold'        => 5,
        // Lower than the others on purpose: leaving a phone number is a far
        // stronger signal than asking a question, so two people is already
        // worth a merchant's attention.
        'restock_requested'     => 2,
    ];

    /**
     * Topic rules for turning a repeated question into a specific action.
     * Matched against normalised text, so Arabic ي/ك and Persian ی/ک both
     * hit. Order matters: the first match wins, most specific first.
     */
    private const TOPIC_RULES = [
        'shipping' => ['ارسال', 'پست', 'تیپاکس', 'پیشتاز', 'کرایه', 'باربری', 'زمان تحویل', 'چند روزه میرسه', 'shipping', 'delivery'],
        'price'    => ['قیمت', 'هزینه', 'چند تومن', 'چنده', 'تعرفه', 'نرخ', 'price', 'cost', 'how much'],
        'contact'  => ['شماره', 'تماس', 'تلفن', 'واتساپ', 'ایمیل', 'آدرس', 'کجاست', 'phone', 'contact', 'email', 'address'],
        'warranty' => ['گارانتی', 'ضمانت', 'مرجوع', 'پس دادن', 'تعویض', 'warranty', 'refund', 'return policy'],
        'payment'  => ['پرداخت', 'اقساط', 'قسطی', 'کارت به کارت', 'درگاه', 'installment', 'payment'],
        'services' => ['خدمات', 'خدماتی', 'چیکار', 'چه کاری', 'services', 'what do you do'],
        'identity' => ['اسم شرکت', 'نام شرکت', 'مدیرعامل', 'مدیر عامل', 'کی هستید', 'company name', 'who are you'],
        'hours'    => ['ساعت کاری', 'ساعات کاری', 'باز هستید', 'تعطیل', 'opening hours', 'open'],
    ];

    /**
     * Builds every suggestion for one chatbot. Pure read; persistence is
     * the caller's job (see GenerateSuggestionsJob).
     *
     * @return array<int, array{type:string, fingerprint:string, params:array, count:int, conversations:array}>
     */
    public function build(string $chatbotId): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $found = array_merge(
            $this->repeatedQuestions($chatbotId, $since),
            $this->unansweredTopics($chatbotId, $since),
            $this->missingFromCatalog($chatbotId, $since),
            $this->comparedPairs($chatbotId, $since),
            $this->openRequests($chatbotId, $since),
        );

        usort($found, fn ($a, $b) => $b['count'] <=> $a['count']);
        return $found;
    }

    /** First topic whose vocabulary appears in the text, or null. */
    public function classifyTopic(string $text): ?string
    {
        $normalized = TextSimilarity::normalize($text);
        foreach (self::TOPIC_RULES as $topic => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, TextSimilarity::normalize($phrase))) {
                    return $topic;
                }
            }
        }
        return null;
    }

    /**
     * The workhorse: the same question, asked by enough different people
     * to mean the answer is missing from the site.
     *
     * Grouped by similarity rather than exact string, so five phrasings of
     * "how much does shipping cost" are one finding with a count of five,
     * not five findings nobody acts on.
     */
    private function repeatedQuestions(string $chatbotId, $since): array
    {
        // messages carries chatbot_id itself, so no join to conversations
        // is needed to scope this to one bot.
        $rows = DB::table('messages')
            ->where('chatbot_id', $chatbotId)
            ->where('role', 'user')
            ->where('created_at', '>=', $since)
            ->select('content', 'conversation_id')
            ->limit(5000)
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $text = trim((string) $r->content);
            // Greetings and one-word noise are not findings.
            if (mb_strlen($text) < 8) continue;
            $items[] = ['text' => $text, 'count' => 1, 'conversation_id' => $r->conversation_id];
        }
        if (!$items) return [];

        $groups = $this->groupWithConversations($items);

        $out = [];
        foreach ($groups as $g) {
            // Counted per distinct conversation: one person asking the same
            // thing five times in one chat is not five people wanting it.
            $people = count($g['conversations']);
            if ($people < self::THRESHOLDS['repeated_question']) continue;

            $topic = $this->classifyTopic($g['label']);
            $out[] = [
                'type'          => $topic ? 'topic_gap' : 'repeated_question',
                'fingerprint'   => ($topic ? "topic:{$topic}" : 'q:' . substr(sha1(TextSimilarity::normalize($g['label'])), 0, 24)),
                'params'        => ['topic' => $topic, 'question' => $g['label'], 'examples' => array_slice($g['variants'], 0, 3)],
                'count'         => $people,
                'conversations' => array_slice($g['conversations'], 0, 10),
            ];
        }
        return $out;
    }

    /** Questions the bot openly failed on — the clearest content gap there is. */
    private function unansweredTopics(string $chatbotId, $since): array
    {
        $rows = DB::table('conversation_events')
            ->where('chatbot_id', $chatbotId)
            ->where('event_type', 'unanswered')
            ->where('created_at', '>=', $since)
            ->selectRaw('payload, conversation_id')
            ->limit(5000)
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $payload = json_decode((string) $r->payload, true) ?: [];
            $q = trim((string) ($payload['query'] ?? ''));
            if ($q === '') continue;
            $items[] = ['text' => $q, 'count' => 1, 'conversation_id' => $r->conversation_id];
        }
        if (!$items) return [];

        $out = [];
        foreach ($this->groupWithConversations($items) as $g) {
            $people = count($g['conversations']);
            if ($people < self::THRESHOLDS['unanswered_topic']) continue;
            $out[] = [
                'type'          => 'unanswered_topic',
                'fingerprint'   => 'unans:' . substr(sha1(TextSimilarity::normalize($g['label'])), 0, 24),
                'params'        => ['question' => $g['label'], 'topic' => $this->classifyTopic($g['label'])],
                'count'         => $people,
                'conversations' => array_slice($g['conversations'], 0, 10),
            ];
        }
        return $out;
    }

    /** Asked for by name, nothing in the catalog matched. */
    private function missingFromCatalog(string $chatbotId, $since): array
    {
        $rows = DB::table('conversation_events')
            ->where('chatbot_id', $chatbotId)
            ->where('event_type', 'sku_lookup')
            ->where('created_at', '>=', $since)
            ->selectRaw('payload, conversation_id')
            ->limit(5000)
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $payload = json_decode((string) $r->payload, true) ?: [];
            if (($payload['match_type'] ?? '') !== 'none') continue;
            $q = trim((string) ($payload['query'] ?? ''));
            if ($q === '') continue;
            $items[] = ['text' => $q, 'count' => 1, 'conversation_id' => $r->conversation_id];
        }
        if (!$items) return [];

        $out = [];
        foreach ($this->groupWithConversations($items) as $g) {
            $people = count($g['conversations']);
            if ($people < self::THRESHOLDS['missing_from_catalog']) continue;
            $out[] = [
                'type'          => 'missing_from_catalog',
                'fingerprint'   => 'missing:' . substr(sha1(TextSimilarity::normalize($g['label'])), 0, 24),
                'params'        => ['request' => $g['label']],
                'count'         => $people,
                'conversations' => array_slice($g['conversations'], 0, 10),
            ];
        }
        return $out;
    }

    /**
     * Two products put side by side often enough that the shop should just
     * publish the comparison instead of making the bot rebuild it each time.
     */
    private function comparedPairs(string $chatbotId, $since): array
    {
        $rows = DB::table('conversation_events')
            ->where('chatbot_id', $chatbotId)
            ->where('event_type', 'tool_called')
            ->where('created_at', '>=', $since)
            ->selectRaw('payload, conversation_id')
            ->limit(5000)
            ->get();

        $pairs = [];
        foreach ($rows as $r) {
            $payload = json_decode((string) $r->payload, true) ?: [];
            if (($payload['tool'] ?? $payload['name'] ?? '') !== 'compare_products') continue;

            $args = $payload['arguments'] ?? $payload['args'] ?? [];
            if (is_string($args)) $args = json_decode($args, true) ?: [];
            $ids = $args['product_ids'] ?? $args['products'] ?? [];
            if (!is_array($ids) || count($ids) < 2) continue;

            // Order-independent: comparing A with B is the same finding as
            // comparing B with A.
            $ids = array_map('strval', $ids);
            sort($ids);
            foreach ($this->pairsOf($ids) as $pair) {
                $key = implode('|', $pair);
                $pairs[$key]['ids'] = $pair;
                $pairs[$key]['conversations'][$r->conversation_id] = true;
            }
        }

        $out = [];
        foreach ($pairs as $key => $p) {
            $people = count($p['conversations']);
            if ($people < self::THRESHOLDS['compared_pair']) continue;
            $out[] = [
                'type'          => 'compared_pair',
                'fingerprint'   => 'pair:' . substr(sha1($key), 0, 24),
                'params'        => ['product_ids' => $p['ids']],
                'count'         => $people,
                'conversations' => array_slice(array_keys($p['conversations']), 0, 10),
            ];
        }
        return $out;
    }

    /**
     * The waitlist, as a suggestion. This is the strongest demand signal
     * the system has: a customer did not merely type a name that matched
     * nothing, they left a phone number and asked to be told. "19 people
     * asked for Medicube, which you don't carry" comes from here.
     *
     * Restock requests are included too, since a product sitting out of
     * stock with people queued on it is a reorder decision, not just a
     * missing-catalog one.
     */
    private function openRequests(string $chatbotId, $since): array
    {
        $rows = DB::table('leads')
            ->selectRaw('type, requested_item, requested_product_id, COUNT(*) AS cnt')
            ->where('chatbot_id', $chatbotId)
            ->whereIn('type', ['out_of_stock', 'not_in_catalog'])
            ->where('request_status', 'open')
            ->whereNotNull('requested_item')
            ->where('created_at', '>=', $since)
            ->groupBy('type', 'requested_item', 'requested_product_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $people = (int) $r->cnt;
            $key = $r->type === 'out_of_stock' ? 'restock_requested' : 'missing_from_catalog';
            if ($people < self::THRESHOLDS[$key === 'restock_requested' ? 'restock_requested' : 'missing_from_catalog']) continue;

            $out[] = [
                'type'        => $key,
                'fingerprint' => $key . ':' . ($r->requested_product_id
                    ? 'pid' . $r->requested_product_id
                    : substr(sha1(TextSimilarity::normalize($r->requested_item)), 0, 24)),
                'params'      => ['request' => $r->requested_item, 'product_id' => $r->requested_product_id],
                'count'       => $people,
                // The conversations are reachable from the Requests page,
                // which is where a merchant acts on these; the suggestion
                // links there rather than duplicating the contact list.
                'conversations' => $this->conversationsForRequest($chatbotId, $r->requested_item),
            ];
        }
        return $out;
    }

    private function conversationsForRequest(string $chatbotId, string $item): array
    {
        return DB::table('leads')
            ->where('chatbot_id', $chatbotId)
            ->where('requested_item', $item)
            ->limit(10)
            ->pluck('conversation_id')
            ->all();
    }

    private function pairsOf(array $ids): array
    {
        $out = [];
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) $out[] = [$ids[$i], $ids[$j]];
        }
        return $out;
    }

    /**
     * TextSimilarity::group() collapses phrasings but does not carry the
     * conversation ids through — and without those a suggestion cannot be
     * checked, which is the whole point. This re-runs the same grouping
     * while keeping each group's real sources.
     */
    private function groupWithConversations(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $text = trim((string) $item['text']);
            if ($text === '') continue;
            $tokens = TextSimilarity::tokens($text);
            if (!$tokens) continue;

            $matched = false;
            foreach ($groups as &$g) {
                if (TextSimilarity::jaccard($tokens, $g['tokens']) >= 0.5) {
                    $g['conversations'][$item['conversation_id']] = true;
                    if (mb_strlen($text) < mb_strlen($g['label'])) $g['label'] = $text;
                    if (count($g['variants']) < 5) $g['variants'][] = $text;
                    $matched = true;
                    break;
                }
            }
            unset($g);

            if (!$matched) {
                $groups[] = [
                    'label' => $text, 'tokens' => $tokens, 'variants' => [$text],
                    'conversations' => [$item['conversation_id'] => true],
                ];
            }
        }

        return array_map(fn ($g) => [
            'label'         => $g['label'],
            'variants'      => array_values(array_unique($g['variants'])),
            'conversations' => array_keys($g['conversations']),
        ], $groups);
    }
}
