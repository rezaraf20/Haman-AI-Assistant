<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use App\Filament\Customer\Pages\Suggestions;
use App\Services\SuggestionEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recomputes the actionable suggestions for every active chatbot (doc-07).
 *
 * Daily rather than on page render: the rules read a 90-day window of
 * messages and events, which is fine once a night and wrong on every
 * portal page load. The portal reads only the suggestions table.
 *
 * Re-running is safe and is the normal case — a finding keyed by its
 * fingerprint gets its count refreshed, and a dismissed one stays
 * dismissed rather than reappearing tomorrow with a slightly higher
 * number.
 */
class GenerateSuggestionsCommand extends Command
{
    protected $signature = 'hamman:generate-suggestions {--tenant= : Only this schema} {--chatbot= : Only this chatbot id}';
    protected $description = 'Rebuild rule-based, zero-cost improvement suggestions from recent conversations';

    public function handle(SuggestionEngine $engine): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $s) => $q->where('schema_name', $s))
            ->orderBy('schema_name')
            ->get();

        $totalWritten = 0;

        foreach ($tenants as $tenant) {
            $schema = $tenant->schema_name;
            $exists = DB::selectOne('SELECT 1 AS ok FROM information_schema.schemata WHERE schema_name = ?', [$schema]);
            if (!$exists) continue;

            DB::statement("SET search_path TO {$schema}, public");
            try {
                $chatbots = DB::table('chatbots')
                    ->when($this->option('chatbot'), fn ($q, $id) => $q->where('id', $id))
                    ->pluck('id');

                foreach ($chatbots as $chatbotId) {
                    $written = $this->rebuildFor($engine, $chatbotId);
                    $totalWritten += $written;
                    if ($written > 0) {
                        $this->line("  {$schema} / {$chatbotId}: {$written} suggestion(s)");
                    }
                }

                // The nav badge never queries — it only reads what this job
                // publishes (see Suggestions::getNavigationBadge()), because
                // it renders on every page of the panel.
                Suggestions::publishBadgeCount(
                    $schema,
                    DB::table('suggestions')->where('status', 'active')->count()
                );
            } catch (\Throwable $e) {
                $this->error("  {$schema}: {$e->getMessage()}");
            } finally {
                DB::statement('SET search_path TO public');
            }
        }

        $this->info("Done — {$totalWritten} suggestion(s) across {$tenants->count()} tenant(s).");
        return self::SUCCESS;
    }

    private function rebuildFor(SuggestionEngine $engine, string $chatbotId): int
    {
        $found = $engine->build($chatbotId);
        $written = 0;

        foreach ($found as $s) {
            $existing = DB::table('suggestions')
                ->where('chatbot_id', $chatbotId)
                ->where('fingerprint', $s['fingerprint'])
                ->first();

            if ($existing) {
                // A dismissed finding is left exactly as it is. Refreshing
                // its count would be harmless, but resurrecting it is the
                // bug this guard exists to prevent, and leaving the row
                // untouched makes that impossible by construction.
                if ($existing->status === 'dismissed') continue;

                DB::table('suggestions')->where('id', $existing->id)->update([
                    'count'                   => $s['count'],
                    'params'                  => json_encode($s['params'], JSON_UNESCAPED_UNICODE),
                    'source_conversation_ids' => json_encode($s['conversations']),
                    'computed_at'             => now(),
                ]);
            } else {
                DB::table('suggestions')->insert([
                    'id'                      => (string) Str::uuid(),
                    'chatbot_id'              => $chatbotId,
                    'type'                    => $s['type'],
                    'fingerprint'             => $s['fingerprint'],
                    'params'                  => json_encode($s['params'], JSON_UNESCAPED_UNICODE),
                    'count'                   => $s['count'],
                    'source_conversation_ids' => json_encode($s['conversations']),
                    'status'                  => 'active',
                    'computed_at'             => now(),
                    'created_at'              => now(),
                ]);
            }
            $written++;
        }

        // A finding that no longer clears its threshold should stop being
        // shown, but must not be deleted: deleting it would lose a
        // dismissal and let it come back the next time it spikes.
        $liveFingerprints = array_column($found, 'fingerprint');
        DB::table('suggestions')
            ->where('chatbot_id', $chatbotId)
            ->where('status', 'active')
            ->when($liveFingerprints, fn ($q) => $q->whereNotIn('fingerprint', $liveFingerprints))
            ->update(['status' => 'stale', 'computed_at' => now()]);

        return $written;
    }
}
