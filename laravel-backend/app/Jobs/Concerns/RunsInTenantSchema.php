<?php
namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Every queued job that reads or writes a specific tenant's schema must
 * carry that schema name in its own serialized state (a job property) and
 * set search_path itself inside handle() — it can never rely on whatever
 * search_path happens to be active on whichever worker/connection picks it
 * up, since a queue worker's own ambient state has nothing to do with the
 * tenant that dispatched the job.
 *
 * Audited 2026-10-07 after a false alarm: 153 historical failed_jobs rows
 * looked like evidence of exactly this bug, but turned out to be two
 * unrelated one-off incidents (a document-ID race during a single sync, and
 * orphaned jobs left over from deleted test tenants) — EmbedDocumentJob
 * (the only queued job that touches tenant-schema data at all; the other
 * two either stay in the public schema or loop every tenant and manage
 * search_path themselves per-iteration) already carried schemaName and set
 * it correctly. This trait centralizes that already-correct pattern so a
 * FUTURE per-tenant job gets it for free instead of needing to hand-roll it
 * again — not a fix for an active bug, a guard against introducing one.
 */
trait RunsInTenantSchema
{
    /**
     * Runs $callback with search_path set to $schemaName, restoring
     * whatever it was before — not hardcoded back to 'public', since a
     * synchronous dispatch (QUEUE_CONNECTION=sync, every local/test run)
     * executes inline on the caller's own connection, which may already be
     * mid-way through its own tenant-schema-scoped work.
     */
    protected function runInTenantSchema(string $schemaName, callable $callback): mixed
    {
        $previousSchema = DB::selectOne('select current_schema() as schema')->schema;
        DB::statement("SET search_path TO {$schemaName}, public");
        try {
            return $callback();
        } finally {
            DB::statement("SET search_path TO {$previousSchema}, public");
        }
    }
}
