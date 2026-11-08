<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records what AnswerOutcome decided, in the two places it belongs:
 *
 *  - ours (empty, fallback, misunderstood) → public.bot_failures, the
 *    platform's internal view;
 *  - not_carried → an 'unanswered' conversation event in the tenant schema,
 *    the same shape the AI service writes ({query, best_score, reason}), so
 *    the customer's demand-gap report (TrendsService, SuggestionEngine)
 *    picks it up. Skipped when the AI service already logged one for this
 *    turn (its retrieval found nothing) — one gap, one event.
 *
 * Used live (ChatService::finish) and by haman:reclassify-answers for
 * history. Never throws: analytics must not break a chat reply.
 */
class AnswerOutcomeRecorder
{
    /**
     * @param array{unanswered:bool, kind:?string} $outcome
     */
    public static function record(array $outcome, string $tenantId, string $chatbotId, ?string $conversationId, ?string $messageId,
                                  ?string $question, ?string $reply, $at, string $source = 'live', bool $aiAlreadyLoggedGap = false): void
    {
        if (!$outcome['unanswered'] || !$outcome['kind']) return;

        try {
            // Its own savepoint: in Postgres a failed statement aborts the
            // whole surrounding transaction, so an analytics write that
            // fails must roll back alone, not take the chat reply with it.
            DB::transaction(fn () => self::write($outcome, $tenantId, $chatbotId, $conversationId, $messageId, $question, $reply, $at, $source, $aiAlreadyLoggedGap));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function write(array $outcome, string $tenantId, string $chatbotId, ?string $conversationId, ?string $messageId,
                                  ?string $question, ?string $reply, $at, string $source, bool $aiAlreadyLoggedGap): void
    {
        {
            if (in_array($outcome['kind'], AnswerOutcome::OURS, true)) {
                $row = [
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'chatbot_id' => $chatbotId,
                    'conversation_id' => $conversationId, 'message_id' => $messageId, 'kind' => $outcome['kind'],
                    'question' => $question !== null ? Str::limit($question, 1000, '') : null,
                    'reply' => $reply !== null ? Str::limit($reply, 1000, '') : null,
                    'source' => $source, 'occurred_at' => $at,
                ];
                // message_id is unique: re-running a backfill must not duplicate.
                DB::table('public.bot_failures')->insertOrIgnore($row);
                return;
            }

            if ($outcome['kind'] === 'not_carried' && $conversationId && !$aiAlreadyLoggedGap && trim((string) $question) !== '') {
                $exists = DB::table('conversation_events')
                    ->where('conversation_id', $conversationId)->where('event_type', 'unanswered')
                    ->where('payload->query', $question)->exists();
                if ($exists) return;

                DB::table('conversation_events')->insert([
                    'id' => (string) Str::uuid(),
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'chatbot_id' => $chatbotId,
                    'event_type' => 'unanswered',
                    'payload' => json_encode(['query' => $question, 'best_score' => null, 'reason' => 'reply_says_not_carried'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $at,
                ]);
            }
        }
    }
}
