<?php
namespace App\Support;

/**
 * Whether a bot reply actually answered, and if not, whose problem it is.
 *
 * is_unanswered used to come from retrieval scores alone, so it was false on
 * all 119 Shop Bot replies — while 26 of them (21.8%) had plainly failed:
 * 13 empty, 5 "Sorry, I could not generate a response", 5 "that isn't in
 * our catalogue / we have no information on that", 3 "I can't do that" or
 * nonsense. A metric that reads 0% there measures nothing.
 *
 * Four outcomes, in this order (the first that matches wins):
 *
 *   empty         — no text at all reached the customer          → us
 *   fallback      — an error/fallback reply (is_fallback), not a
 *                   quota message                                → us
 *   misunderstood — the bot said it can't do it / didn't follow
 *                   ("نمی‌توانم…", "I can't…")                    → us
 *   not_carried   — "we don't have it / no information on that"
 *                   (in the reply text), or retrieval found
 *                   nothing (the AI service's own flag)          → the shop
 *
 * "Ours" (empty, fallback, misunderstood) go to the platform's internal
 * Bot failures view; not_carried goes to the customer's demand-gap report
 * — something a shopper asked for that the shop doesn't offer or describe.
 *
 * The patterns are deliberately narrow and come from real replies, and
 * they are checked against the reply's OPENING (its first two sentences). A reply that
 * failed says so up front ("we don't have a blue mug…", "I couldn't find a
 * flask…"); a reply that answers and then mentions one missing detail
 * further in ("…here are the lunch bags we carry: 1. … — the price of the
 * sharpener isn't in our list") answered. Checked against the whole text,
 * the first production run flagged 50 of Shop Bot's 119 replies; about
 * half were real answers of that second kind.
 *
 * "The price of X isn't in our list" inside an answer is a missing detail
 * (data the shop can add), not "we don't carry it" — the stationery-bundle
 * replies listed every item with its price and said exactly that about one.
 *
 * A leaked "assistant:" prefix is a formatting defect, not a non-answer
 * (those replies did answer), so it is not counted here.
 */
class AnswerOutcome
{
    /** Ours to fix. missed_product: said "we don't have it" about a product the shop had in stock (AnswerOutcomeRecorder + CatalogMatch). */
    public const OURS = ['empty', 'fallback', 'misunderstood', 'missed_product'];

    private const MISUNDERSTOOD = [
        '/نمی[‌\s]?توانم/u',
        '/متوجه\s+(منظور\S*\s+)?نشدم/u',
        '/منظور(تان|ت|تون)\s+(را|رو|چیست|چیه)/u',
        '/دسترسی\s+(مستقیم\s+)?ندارم/u',
        "/\\bI\\s+(can(?:no|')t|cannot|am\\s+unable|'m\\s+unable)\\b/iu",
        "/\\bI\\s+don'?t\\s+understand\\b/iu",
        '/\bcould\s+you\s+(please\s+)?clarify\b/iu',
    ];

    private const NOT_CARRIED = [
        '/موجود\s+نیست/u',
        '/موجود\s+نمی[‌\s]?باشد/u',
        '/نداریم/u',
        '/ذکر\s+نشده/u',
        '/اشاره[‌\s]?ای\s+.{0,40}نشده/u',
        '/اطلاعات(ی)?\s+.{0,30}(ندارم|در\s+دسترس\s+نیست|پیدا\s+نکردم)/u',
        '/پیدا\s+نکردم/u',
        '/نتیجه[‌\s]?ای\s+نداشت/u',
        "/\\bwe\\s+don'?t\\s+(carry|have|stock|sell)\\b/iu",
        "/\\b(is\\s+not|isn'?t|not)\\s+available\\b/iu",
        "/\\bno\\s+information\\b|\\bdon'?t\\s+have\\s+(any\\s+)?information\\b/iu",
        "/\\bcould(n'?t|\\s+not)\\s+find\\b/iu",
    ];

    /**
     * @param array{response?:?string,is_fallback?:bool,is_unanswered?:bool,finish_reason?:?string} $result
     * @return array{unanswered:bool, kind:?string}
     */
    public static function classify(array $result): array
    {
        $text = trim((string) ($result['response'] ?? ''));
        $reason = (string) ($result['finish_reason'] ?? '');

        // Answers that are policy, not failure: the quota message, and the
        // lead-capture prompts that deliberately replace an answer.
        if ($reason === 'quota_exceeded' || str_starts_with($reason, 'lead_')) {
            return ['unanswered' => false, 'kind' => null];
        }

        if ($text === '') return ['unanswered' => true, 'kind' => 'empty'];
        if (($result['is_fallback'] ?? false) && !($result['is_unanswered'] ?? false)) {
            return ['unanswered' => true, 'kind' => 'fallback'];
        }
        $opening = self::openingSentence($text);
        // A missing price is a missing detail, not a missing product.
        $opening = preg_replace('/قیمت[^.،؛:]{0,40}?(موجود\s+نیست|ذکر\s+نشده(\s+است)?)/u', '', $opening) ?? $opening;
        foreach (self::MISUNDERSTOOD as $re) {
            if (preg_match($re, $opening)) return ['unanswered' => true, 'kind' => 'misunderstood'];
        }
        foreach (self::NOT_CARRIED as $re) {
            if (preg_match($re, $opening)) return ['unanswered' => true, 'kind' => 'not_carried'];
        }
        if ($result['is_unanswered'] ?? false) return ['unanswered' => true, 'kind' => 'not_carried'];

        return ['unanswered' => false, 'kind' => null];
    }

    /**
     * The opening of a reply: its first two sentences (split on . ! ? ؟ or a
     * line break), after dropping a leading greeting, a leaked "assistant:"
     * prefix and the filler "من دوباره به شما پاسخ می‌دهم", with markdown
     * links and URLs reduced to their text so a long link cannot push the
     * actual sentence out of view. At most 300 characters.
     */
    public static function openingSentence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('#https?://\S+#u', '', $text) ?? $text;
        $text = preg_replace('/^\s*assistant\s*:\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^\s*(سلام|درود|hi|hello)[^.!?؟\n]{0,12}[.!?؟\n]\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^\s*(سلام[،,]?\s*)?من\s+دوباره\s+به\s+شما\s+پاسخ\s+می[‌\s]?دهم[.!]?\s*/u', '', $text) ?? $text;
        $sentences = preg_split('/(?<=[.!?؟])\s+|\n+/u', $text, 3) ?: [$text];
        return mb_substr(implode(' ', array_slice($sentences, 0, 2)), 0, 300);
    }
}
