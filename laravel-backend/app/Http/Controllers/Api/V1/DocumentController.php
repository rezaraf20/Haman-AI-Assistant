<?php
namespace App\Http\Controllers\Api\V1;
use App\Models\Tenant\Document;
use App\Services\AiGatewayService;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class DocumentController extends BaseApiController {
    public function __construct(private AiGatewayService $ai) {}

    public function store(Request $req, string $chatbotId): JsonResponse {
        $d = $req->validate([
            'title'       => 'required|string|max:500',
            'content'     => 'required|string|min:10',
            'source_type' => 'required|in:manual,url,woocommerce,wordpress',
            'source_url'  => 'nullable|string|max:2000',
        ]);
        $tenant = app('current_tenant');
        $doc = Document::create([
            'id'           => Str::uuid(),
            'chatbot_id'   => $chatbotId,
            'source_type'  => $d['source_type'],
            'source_url'   => $d['source_url'] ?? null,
            'external_id'  => Str::uuid(),
            'title'        => $d['title'],
            'raw_content'  => $d['content'],
            'content_hash' => hash('sha256', $d['content']),
            'language'     => 'en',
            'status'       => 'pending',
        ]);
        try {
            $this->ai->embedDocument($doc->id, $chatbotId, $tenant->schema_name);
            $doc->update(['status' => 'indexed']);
        } catch (\Throwable $e) {
            $doc->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
        return $this->created($doc->fresh());
    }
}