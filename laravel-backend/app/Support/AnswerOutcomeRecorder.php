<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records what AnswerOutcome decided, where it belongs:
 *
 *  - ours (empty, fallback, misunderstood, missed_product) → a row in
 *    public.bot_failures, the platform's internal view;
 *  - not_carried → an 'unanswered' conversation event in the tenant schema,
 *    the shape the AI service writes ({query, best_score, reason}), so the
 *    customer's demand-gap report (TrendsService, SuggestionEngine) picks it
 *    up — not duplicated when the AI service already logged the gap.
 *
 * Before a "we don't have it" becomes the shop's demand gap, the question is
 * matched against the shop's own catalogue (CatalogMatch). When an in-stock
 * product matches, the shop HAD it: that is our failure (missed_product,
 * with the product named), not her gap. On khonehrangi.ir's history this
 * was 6 of 17 "we don't have it" replies. A match that is only out of stock
 * stays her gap — a restock signal — with the product noted.
 *
 * Every "we don't have it" judgement (missed_product and not_carried) is
 * also kept in bot_failures, so the platform can read the rate "said we
 * don't have it while we did" across all shops (the admin page's headline;
 * the failures list itself shows only ours).
 *
 * Used live (ChatService::finish) and by haman:reclassify-answers. Never
 * throws: analytics must not break a chat reply.
 */
class AnswerOutcomeRecorder
{
    /**
     * @param array{unanswered:bool, kind:?string} $outcome
     * @param array|null $catalogue products to check a "not carried" against (default: this chatbot's own)
     * @return ?string the kind finally recorded (missed_product replaces not_carried when the shop had it)
     */
    public static function record(array $outcome, string $tenantId, string $chatbotId, ?string $conversationId, ?string $messageId,
                                  ?string $question, ?string $reply, $at, string $source = 'live', bool $aiAlreadyLoggedGap = false,
                                  ?array $catalogue = null): ?string
    {
        if (!$outcome['unanswered'] || !$outcome['kind']) return null;

        try {
            // Its own savepoint: in Postgres a failed statement aborts the
            // whole surrounding transaction, so an analytics write that
            // fails must roll back alone, not take the chat reply with it.
            return DB::transaction(fn () => self::write($outcome['kind'], $tenantId, $chatbotId, $conversationId, $messageId, $question, $reply, $at, $source, $aiAlreadyLoggedGap, $catalogue));
        } catch (\Throwable $e) {
            report($e);
            return $outcome['kind'];
        }
    }

    private static function write(string $kind, string $tenantId, string $chatbotId, ?string $conversationId, ?string $messageId,
                                  ?string $question, ?string $reply, $at, string $source, bool $aiAlreadyLoggedGap, ?array $catalogue): string
    {
        $matched = null;
        if ($kind === 'not_carried' && trim((string) $question) !== '') {
            $match = CatalogMatch::find((string) $question, $catalogue ?? CatalogMatch::catalogue($chatbotId));
            if ($match !== null) {
                $matched = $match['name'];
                if (($match['stock_status'] ?? 'instock') !== 'outofstock') $kind = 'missed_product';
            }
        }

        // Ours, and every "we don't have it" (for the rate) — one row per reply.
        if (in_array($kind, AnswerOutcome::OURS, true) || $kind === 'not_carried') {
            DB::table('public.bot_failures')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'chatbot_id' => $chatbotId,
                'conversation_id' => $conversationId, 'message_id' => $messageId, 'kind' => $kind,
                'question' => $question !== null ? Str::limit($question, 1000, '') : null,
                'reply' => $reply !== null ? Str::limit($reply, 1000, '') : null,
                'matched_product' => $matched !== null ? Str::limit($matched, 490, '') : null,
                'source' => $source, 'occurred_at' => $at,
            ]);
        }

        if ($kind === 'not_carried' && $conversationId && !$aiAlreadyLoggedGap && trim((string) $question) !== '') {
            $exists = DB::table('conversation_events')
                ->where('conversation_id', $conversationId)->where('event_type', 'unanswered')
                ->where('payload->query', $question)->exists();
            if (!$exists) {
                DB::table('conversation_events')->insert([
                    'id' => (string) Str::uuid(),
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'chatbot_id' => $chatbotId,
                    'event_type' => 'unanswered',
                    'payload' => json_encode(
                        ['query' => $question, 'best_score' => null, 'reason' => 'reply_says_not_carried']
                        // Carried, but out of stock: a restock signal for the shop.
                        + ($matched !== null ? ['out_of_stock_product' => $matched] : []),
                        JSON_UNESCAPED_UNICODE),
                    'created_at' => $at,
                ]);
            }
        }

        return $kind;
    }

    /**
     * The headline rate: of every "we don't have it" reply, how many were
     * about a product the shop had in stock. Better than recall on a
     * synthetic set — it is measured on real customers' real questions.
     * @return array{missed:int, total:int, pct:?float}
     */
    public static function missedProductRate(?\DateTimeInterface $since = null, ?string $chatbotId = null): array
    {
        $q = DB::table('public.bot_failures')->whereIn('kind', ['missed_product', 'not_carried']);
        if ($since) $q->where('occurred_at', '>=', $since);
        if ($chatbotId) $q->where('chatbot_id', $chatbotId);
        $row = $q->selectRaw("count(*) FILTER (WHERE kind = 'missed_product') AS missed, count(*) AS total")->first();
        $total = (int) ($row->total ?? 0);
        return ['missed' => (int) ($row->missed ?? 0), 'total' => $total, 'pct' => $total ? round(100 * ($row->missed ?? 0) / $total, 1) : null];
    }
}
