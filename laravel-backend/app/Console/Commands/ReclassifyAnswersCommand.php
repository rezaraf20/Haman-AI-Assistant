<?php
namespace App\Console\Commands;

use App\Support\{AnswerOutcome, AnswerOutcomeRecorder};
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-judges a chatbot's past replies with App\Support\AnswerOutcome and
 * records a baseline.
 *
 * Reads a conversation export (the JSON written before Shop Bot was
 * retired: {chatbot, conversations, messages, conversation_events}), so the
 * judgement uses exactly the history that was saved, then:
 *   - sets messages.is_unanswered on the rows that still exist,
 *   - files our failures in bot_failures and "we don't have that" as
 *     'unanswered' events (the demand-gap report), via AnswerOutcomeRecorder,
 *   - writes one answer_quality_baselines row with the measured rate.
 *
 * Idempotent: bot_failures is unique per message and an 'unanswered' event
 * is not duplicated for the same conversation and question. --dry-run
 * prints the numbers and changes nothing.
 */
class ReclassifyAnswersCommand extends Command
{
    protected $signature = 'haman:reclassify-answers {json : path to a conversation export} {--dry-run}';
    protected $description = 'Re-judge a chatbot\'s past replies (empty / fallback / misunderstood / not carried) from an export, fix is_unanswered, and record a baseline';

    public function handle(): int
    {
        $path = $this->argument('json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($data) || empty($data['chatbot']['chatbot_id']) || !isset($data['messages'])) {
            $this->error("Not a conversation export: {$path}");
            return self::FAILURE;
        }

        $chatbotId = $data['chatbot']['chatbot_id'];
        $index = DB::table('public.chatbot_index')->where('chatbot_id', $chatbotId)->first();
        if (!$index) {
            $this->error("Chatbot {$chatbotId} is not in chatbot_index.");
            return self::FAILURE;
        }

        $byConversation = collect($data['messages'])->sortBy('created_at')->groupBy('conversation_id');
        $counts = ['empty' => 0, 'fallback' => 0, 'misunderstood' => 0, 'not_carried' => 0];
        $replies = 0;
        $judged = [];

        foreach ($byConversation as $conversationId => $messages) {
            $lastQuestion = null;
            foreach ($messages as $m) {
                if ($m['role'] === 'user') { $lastQuestion = $m['content']; continue; }
                if ($m['role'] !== 'assistant') continue;
                $replies++;
                $outcome = AnswerOutcome::classify([
                    'response' => $m['content'],
                    'is_fallback' => (bool) ($m['is_fallback'] ?? false),
                    'is_unanswered' => (bool) ($m['is_unanswered'] ?? false),
                ]);
                if ($outcome['kind']) $counts[$outcome['kind']]++;
                $judged[] = [$m, $outcome, $lastQuestion, $conversationId];
            }
        }

        $failed = array_sum($counts);
        $pct = $replies ? round(100 * $failed / $replies, 1) : 0.0;
        $this->table(['replies', 'empty', 'fallback', 'misunderstood', 'not_carried', 'failed', 'failed %'],
            [[$replies, $counts['empty'], $counts['fallback'], $counts['misunderstood'], $counts['not_carried'], $failed, $pct]]);

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing written.');
            return self::SUCCESS;
        }

        DB::statement("SET search_path TO {$index->schema_name}, public");
        try {
            $updated = 0;
            foreach ($judged as [$m, $outcome, $question, $conversationId]) {
                $updated += DB::table('messages')->where('id', $m['id'])->update(['is_unanswered' => $outcome['unanswered']]);
                AnswerOutcomeRecorder::record($outcome, (string) $index->tenant_id, $chatbotId, $conversationId, $m['id'],
                    $question, $m['content'], Carbon::parse($m['created_at']), 'backfill');
            }
        } finally {
            DB::statement('SET search_path TO public');
        }

        $dates = collect($data['messages'])->pluck('created_at')->filter()->sort()->values();
        DB::table('public.answer_quality_baselines')->insert([
            'id' => (string) Str::uuid(),
            'chatbot_id' => $chatbotId,
            'chatbot_name' => $data['chatbot']['name'] ?? $index->name,
            'period_from' => $dates->first(),
            'period_to' => $dates->last(),
            'replies' => $replies,
            'failed' => $failed,
            'failed_pct' => $pct,
            'by_kind' => json_encode($counts),
            'source' => Str::limit(basename($path), 120, ''),
            'measured_at' => now(),
        ]);

        $this->info("is_unanswered set on {$updated} stored replies; baseline recorded ({$pct}% failed).");
        return self::SUCCESS;
    }
}
