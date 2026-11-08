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
 *                   ("نمی‌توانم…", "I can't…", a leaked "assistant:"
 *                   prefix)                                      → us
 *   not_carried   — "we don't have it / no information on that"
 *                   (in the reply text), or retrieval found
 *                   nothing (the AI service's own flag)          → the shop
 *
 * "Ours" (empty, fallback, misunderstood) go to the platform's internal
 * Bot failures view; not_carried goes to the customer's demand-gap report
 * — something a shopper asked for that the shop doesn't offer or describe.
 *
 * The patterns are deliberately narrow and come from real replies: "…is
 * available in our store" and "yes, we have…" must not match (both did
 * under the first, broader attempt).
 */
class AnswerOutcome
{
    public const OURS = ['empty', 'fallback', 'misunderstood'];

    private const MISUNDERSTOOD = [
        '/نمی[‌\s]?توانم/u',
        '/متوجه\s+(منظور\S*\s+)?نشدم/u',
        '/منظور(تان|ت|تون)\s+(را|رو|چیست|چیه)/u',
        '/دسترسی\s+(مستقیم\s+)?ندارم/u',
        '/^\s*assistant\s*:/iu',
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
        foreach (self::MISUNDERSTOOD as $re) {
            if (preg_match($re, $text)) return ['unanswered' => true, 'kind' => 'misunderstood'];
        }
        foreach (self::NOT_CARRIED as $re) {
            if (preg_match($re, $text)) return ['unanswered' => true, 'kind' => 'not_carried'];
        }
        if ($result['is_unanswered'] ?? false) return ['unanswered' => true, 'kind' => 'not_carried'];

        return ['unanswered' => false, 'kind' => null];
    }
}
