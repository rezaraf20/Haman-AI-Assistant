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
        DB::statement("SET search_path TO {$this->schemaName}, public");
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
    }

    public function backoff(): array { return [60,300,1800]; }
}
