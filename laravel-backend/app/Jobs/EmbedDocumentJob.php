<?php namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\{SerializesModels,InteractsWithQueue};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Concerns\RunsInTenantSchema;
use App\Services\AiGatewayService;
use App\Models\Tenant\{Document, SyncJob};
use Illuminate\Support\Facades\DB;

class EmbedDocumentJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RunsInTenantSchema;
    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly string $documentId,
        public readonly string $chatbotId,
        public readonly string $schemaName,
        public readonly ?string $syncJobId = null,
    ) {
        $this->onQueue('embeddings');
    }

    public function handle(AiGatewayService $ai): void {
        $this->runInTenantSchema($this->schemaName, function () use ($ai) {
            $doc = Document::findOrFail($this->documentId);
            $doc->update(['status'=>'processing']);
            try {
                $ai->embedDocument($this->documentId, $this->chatbotId, $this->schemaName);
                $doc->update(['status'=>'indexed','indexed_at'=>now()]);
            } catch (\Throwable $e) {
                $doc->increment('retry_count');
                $doc->update(['status'=>$doc->retry_count>=3?'failed':'pending','error_message'=>$e->getMessage()]);
                throw $e;
            } finally {
                // Only once this document is actually done, not on a
                // transient failure still waiting for its automatic retry
                // (status stays 'pending' for that) — a batch isn't over
                // just because one document in it hit a retryable error.
                if ($doc->fresh()->status !== 'pending') {
                    $this->maybeCloseOutSyncJob();
                }
            }
        });
    }

    /**
     * Laravel calls this once every retry is exhausted, however the job
     * failed — including Document::findOrFail() itself throwing (the
     * document was deleted out from under an already-queued job), which
     * handle()'s own try/finally never sees since that document row never
     * existed to update a status on. Without this, a sync job whose only
     * problem document vanished mid-flight would sit at 'indexing' forever
     * — exactly the kind of silent, un-investigated state this whole fix
     * exists to prevent.
     *
     * Forces the document itself to 'failed' first, rather than trusting
     * whatever handle()'s own catch block already left it at: under
     * QUEUE_CONNECTION=sync specifically, there is no real worker to pull
     * this job again, so reaching failed() after a single attempt still
     * leaves retry_count at 1 (< 3) and status at 'pending' — "waiting for
     * a retry that is never actually coming" is not a status
     * maybeCloseOutSyncJob() can tell apart from "still genuinely in
     * progress" without this.
     */
    public function failed(\Throwable $e): void {
        try {
            $this->runInTenantSchema($this->schemaName, function () use ($e) {
                DB::table('documents')->where('id', $this->documentId)->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                $this->maybeCloseOutSyncJob();
            });
        } catch (\Throwable $inner) {
            report($inner);
        }
    }

    /**
     * The other half of SyncService's 'indexing' status (see its own
     * docblock): once every document this specific sync run dispatched for
     * embedding has either indexed or permanently failed, flip the sync
     * job to its real final status. Whichever EmbedDocumentJob instance
     * happens to finish last does this — there is no separate poller.
     */
    private function maybeCloseOutSyncJob(): void {
        if (!$this->syncJobId) return;

        $stillWorking = DB::table('documents')
            ->where('sync_job_id', $this->syncJobId)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
        if ($stillWorking) return;

        $failedCount = DB::table('documents')->where('sync_job_id', $this->syncJobId)->where('status', 'failed')->count();

        SyncJob::where('id', $this->syncJobId)
            ->where('status', 'indexing')
            ->update([
                'status'       => $failedCount > 0 ? 'indexed_with_errors' : 'completed',
                'completed_at' => now(),
            ]);
    }

    public function backoff(): array { return [60,300,1800]; }
}
