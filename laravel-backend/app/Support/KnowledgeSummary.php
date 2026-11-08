<?php
namespace App\Support;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * What one chatbot's index holds, by kind of content — products, product
 * PDFs, pages, posts, each custom post type, FAQs: live documents, chunks,
 * last sync, archived — plus the plan's document limit and how many items
 * the most recent sync runs refused because of it.
 *
 * One query set, two readers: the customer portal's Knowledge page and the
 * WordPress plugin's settings page (GET /chatbots/{id}/knowledge), so the
 * merchant sees the same numbers in both places.
 *
 * Runs under the tenant's schema and always puts search_path back.
 */
class KnowledgeSummary
{
    /**
     * @return array{kinds: list<array{source_type: string, post_type: ?string, post_type_label: ?string, documents: int, chunks: int, archived: int, last_sync: ?string}>, live_total: int, limit: ?int, over_limit_last_runs: int}
     */
    public static function forChatbot(string $chatbotId, Tenant $tenant): array
    {
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            $groups = DB::select("
                SELECT source_type,
                       metadata->>'post_type' AS post_type,
                       MAX(metadata->>'post_type_label') AS post_type_label,
                       COUNT(*) FILTER (WHERE status <> 'archived') AS documents,
                       COUNT(*) FILTER (WHERE status = 'archived') AS archived,
                       COALESCE(SUM(chunk_count) FILTER (WHERE status <> 'archived'), 0) AS chunks,
                       MAX(last_synced_at) AS last_sync
                FROM documents
                WHERE chatbot_id = ?
                GROUP BY source_type, metadata->>'post_type'
            ", [$chatbotId]);

            // The latest run of each kind — over_limit is per run (see
            // SyncService::upsertDoc()), so only the most recent one says
            // whether the limit is biting right now.
            $latestRuns = DB::select("
                SELECT DISTINCT ON (job_type) job_type, result
                FROM sync_jobs
                WHERE chatbot_id = ? AND job_type IN ('products', 'pages', 'faqs')
                ORDER BY job_type, created_at DESC
            ", [$chatbotId]);
        } finally {
            DB::statement('SET search_path TO public');
        }

        $kinds = [];
        $liveTotal = 0;
        foreach ($groups as $g) {
            $liveTotal += (int) $g->documents;
            $kinds[] = [
                'source_type'     => $g->source_type,
                'post_type'       => $g->post_type,
                'post_type_label' => $g->post_type_label,
                'documents'       => (int) $g->documents,
                'chunks'          => (int) $g->chunks,
                'archived'        => (int) $g->archived,
                'last_sync'       => $g->last_sync ? \Carbon\Carbon::parse($g->last_sync)->toIso8601String() : null,
            ];
        }
        usort($kinds, fn ($a, $b) => $b['documents'] <=> $a['documents']);

        $overLimit = 0;
        foreach ($latestRuns as $run) {
            $overLimit += (int) (json_decode((string) $run->result, true)['over_limit'] ?? 0);
        }

        $limit = Plan::whereKey($tenant->plan_id)->value('max_documents');

        return [
            'kinds'                => $kinds,
            'live_total'           => $liveTotal,
            'limit'                => $limit !== null ? (int) $limit : null,
            'over_limit_last_runs' => $overLimit,
        ];
    }
}
