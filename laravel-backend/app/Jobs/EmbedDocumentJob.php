<?php namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\{SerializesModels,InteractsWithQueue};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\AiGatewayService;
use App\Models\Tenant\Document;
use Illuminate\Support\Facades\DB;

class EmbedDocumentJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly string $documentId,
        public readonly string $chatbotId,
        public readonly string $schemaName
    ) {
        $this->onQueue('embeddings');
    }

    public function handle(AiGatewayService $ai): void {
        // Restored in finally, not hardcoded back to 'public': with
        // QUEUE_CONNECTION=sync (every test run, see DEPLOY.md), dispatch()
        // runs handle() inline on the CALLER's own connection — SyncService
        // dispatches this mid-loop while still relying on its own tenant
        // schema being active for the rest of that loop. A worker's real,
        // queued dispatch starts from 'public' anyway, so restoring "what it
        // was before" gives the same end state there — it's the strictly
        // more correct rule either way, not a special case for sync.
        $previousSchema = DB::selectOne('select current_schema() as schema')->schema;
        DB::statement("SET search_path TO {$this->schemaName}, public");
        try {
            $doc = Document::findOrFail($this->documentId);
            $doc->update(['status'=>'processing']);
            try {
                $ai->embedDocument($this->documentId, $this->chatbotId, $this->schemaName);
                $doc->update(['status'=>'indexed','indexed_at'=>now()]);
            } catch (\Throwable $e) {
                $doc->increment('retry_count');
                $doc->update(['status'=>$doc->retry_count>=3?'failed':'pending','error_message'=>$e->getMessage()]);
                throw $e;
            }
        } finally {
            DB::statement("SET search_path TO {$previousSchema}, public");
        }
    }

    public function backoff(): array { return [60,300,1800]; }
}
