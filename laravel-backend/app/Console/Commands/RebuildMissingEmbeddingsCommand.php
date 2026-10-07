<?php
namespace App\Console\Commands;

use App\Jobs\EmbedDocumentJob;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds every document across every tenant that is NOT actually indexed
 * (status != 'indexed', which also catches one stuck at 'pending' or
 * 'processing' from a job that never finished) and re-queues it.
 *
 * Built after the 2026-10-07 EmbedDocumentJob/sync-status investigation —
 * not because that investigation found a live backlog (it didn't: the one
 * tenant with real documents was already 100% indexed, and the other live
 * tenants had never synced any content in the first place, which this
 * command cannot fix — there's nothing to re-queue for a tenant with zero
 * rows in `documents`), but because recovering from a *future* embedding
 * outage needs a real command, not another round of tinker one-liners.
 */
class RebuildMissingEmbeddingsCommand extends Command
{
    protected $signature = 'haman:rebuild-missing-embeddings {--dry-run : report only, queue nothing}';
    protected $description = 'Re-queue EmbedDocumentJob for every document that is not actually indexed, across every tenant';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $schemas = DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'");

        $totalQueued = 0;
        $totalChars = 0;
        $perTenant = [];

        foreach ($schemas as $s) {
            $schema = $s->schema_name;
            $tenant = DB::table('tenants')->where('schema_name', $schema)->first(['email']);
            DB::statement("SET search_path TO {$schema}, public");

            try {
                $missing = DB::table('documents')
                    ->where('status', '!=', 'indexed')
                    ->where('status', '!=', 'archived')
                    ->get(['id', 'chatbot_id', 'status', 'raw_content']);
            } catch (\Throwable $e) {
                DB::statement('SET search_path TO public');
                continue;
            }

            if ($missing->isEmpty()) {
                DB::statement('SET search_path TO public');
                continue;
            }

            $chars = $missing->sum(fn ($d) => mb_strlen((string) $d->raw_content));
            $perTenant[] = ['schema' => $schema, 'email' => $tenant?->email, 'count' => $missing->count(), 'chars' => $chars];
            $totalChars += $chars;

            if (!$dryRun) {
                foreach ($missing as $doc) {
                    EmbedDocumentJob::dispatch($doc->id, $doc->chatbot_id, $schema);
                    $totalQueued++;
                }
            } else {
                $totalQueued += $missing->count();
            }

            DB::statement('SET search_path TO public');
        }

        if (empty($perTenant)) {
            $this->info('Nothing to rebuild — every document across every tenant is already indexed (or there are none).');
            return self::SUCCESS;
        }

        foreach ($perTenant as $row) {
            $this->line("{$row['schema']} ({$row['email']}): {$row['count']} document(s) not indexed");
        }

        // Rough estimate only: ~4 characters per token is the standard
        // heuristic, not a real tokenizer count — embed.py's actual request
        // is what the real cost comes from, this just gives an order of
        // magnitude before committing to the recovery run.
        $estimatedTokens = (int) ceil($totalChars / 4);
        $costPer1M = (float) Settings::get('pricing.embedding_cost_per_1m_toman');
        $estimatedCostToman = $costPer1M > 0 ? round($estimatedTokens / 1_000_000 * $costPer1M) : null;

        $this->info("Total: " . count($perTenant) . " tenant(s), {$totalQueued} document(s) " . ($dryRun ? 'would be queued' : 'queued') . '.');
        $this->info("Estimated tokens: ~" . number_format($estimatedTokens) . ' (rough, ~4 chars/token)');
        $this->info($estimatedCostToman !== null
            ? 'Estimated embedding cost: ~' . number_format($estimatedCostToman) . ' toman (at pricing.embedding_cost_per_1m_toman)'
            : 'Estimated embedding cost: unknown — pricing.embedding_cost_per_1m_toman is not set.');

        if ($dryRun) {
            $this->warn('Dry run — nothing was actually queued. Re-run without --dry-run to queue these.');
        }

        return self::SUCCESS;
    }
}
