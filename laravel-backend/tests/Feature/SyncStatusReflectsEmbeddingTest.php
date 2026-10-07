<?php
namespace Tests\Feature;

use App\Models\{Tenant, Plan};
use App\Models\Tenant\SyncJob;
use App\Services\TenantService;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the 2026-10-07 sync/embedding investigation:
 * SyncService used to mark a sync_jobs row 'completed' the instant it
 * finished DISPATCHING EmbedDocumentJob for each new/updated document —
 * before any of them had actually run. A merchant (or ChatbotStatusWidget)
 * reading 'completed' as "my bot knows this content now" was reading a
 * status that could be lying the whole embedding pipeline long.
 *
 * Also guards the ordering fix this needed: under QUEUE_CONNECTION=sync
 * (every test run — see phpunit.xml), EmbedDocumentJob::dispatch() runs
 * handle() immediately, before the dispatching loop even continues. The
 * sync job has to be marked 'indexing' BEFORE that first dispatch, not
 * after the whole loop — otherwise EmbedDocumentJob::maybeCloseOutSyncJob()
 * finds the job still at 'running' (not 'indexing' yet) and its own guard
 * never matches, leaving the job stuck at 'indexing' forever once the loop
 * finally does set it.
 *
 * SyncService's own methods (unlike EmbedDocumentJob) don't set
 * search_path themselves — same ambient-context convention as the rest of
 * this app's synchronous request-path code, which normally runs behind
 * ValidateChatbotDomain middleware. Called directly here, so each test sets
 * it explicitly, matching DocumentSyncSkipTest's own pattern.
 */
class SyncStatusReflectsEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DB::statement('SET search_path TO public');
        parent::tearDown();
    }

    private function makeTenantWithChatbot(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Test Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('chatbotId', 'schema');
        // Deliberately leaves search_path set to this tenant's schema --
        // SyncService's own methods need it, and sync_jobs/documents both
        // live in it too, so every assertion below runs in the same
        // context the real call would. tearDown() resets it for the next test.
    }

    public function test_sync_does_not_report_completed_until_embedding_actually_finishes(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeTenantWithChatbot();
        Http::fake(['*/ai/embed/document' => Http::response(['chunks' => 3], 200)]);

        $job = app(\App\Services\SyncService::class)->syncFaqs($chatbotId, [
            ['question' => 'What are your hours?', 'answer' => 'Nine to six.'],
        ], $schema);

        // QUEUE_CONNECTION=sync means the embed already ran by the time
        // syncFaqs() returns -- this is the real, correct final status,
        // not a lie told before the work happened.
        $job = SyncJob::find($job->id);
        $this->assertSame('completed', $job->status);
        $this->assertNotNull($job->completed_at);

        $doc = DB::table('documents')->where('chatbot_id', $chatbotId)->first();
        $this->assertSame('indexed', $doc->status);
        $this->assertSame($job->id, $doc->sync_job_id);
    }

    public function test_a_batch_only_completes_once_every_dispatched_document_resolves(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeTenantWithChatbot();
        Http::fake(['*/ai/embed/document' => Http::response(['chunks' => 1], 200)]);

        $job = app(\App\Services\SyncService::class)->syncFaqs($chatbotId, [
            ['question' => 'Q1', 'answer' => 'A1'],
            ['question' => 'Q2', 'answer' => 'A2'],
            ['question' => 'Q3', 'answer' => 'A3'],
        ], $schema);

        $this->assertSame('completed', SyncJob::find($job->id)->status);

        $indexedCount = DB::table('documents')->where('sync_job_id', $job->id)->where('status', 'indexed')->count();
        $this->assertSame(3, $indexedCount);
    }

    public function test_a_sync_with_nothing_new_to_embed_completes_immediately_without_touching_indexing(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeTenantWithChatbot();

        // syncPages() with an empty list: nothing is new/updated/skipped,
        // so nothing is ever dispatched for embedding at all -- this must
        // never pass through 'indexing' on its way to 'completed'.
        $job = app(\App\Services\SyncService::class)->syncPages($chatbotId, [], $schema);

        $job = SyncJob::find($job->id);
        $this->assertSame('completed', $job->status);
        $this->assertNotNull($job->completed_at);
    }

    public function test_the_embed_failure_path_still_closes_out_the_batch(): void
    {
        ['chatbotId' => $chatbotId, 'schema' => $schema] = $this->makeTenantWithChatbot();
        Http::fake(['*/ai/embed/document' => Http::response(['error' => 'boom'], 500)]);

        $job = app(\App\Services\SyncService::class)->syncFaqs($chatbotId, [
            ['question' => 'Q1', 'answer' => 'A1'],
        ], $schema);

        // tries=3 with a real HTTP failure exhausts its retries
        // synchronously under QUEUE_CONNECTION=sync, landing on
        // 'indexed_with_errors' rather than leaving the batch stuck.
        $this->assertSame('indexed_with_errors', SyncJob::find($job->id)->status);
    }
}
