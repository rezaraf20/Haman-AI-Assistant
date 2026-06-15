<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use App\Exceptions\AiServiceException;

class AiGatewayService {
    private string $url;
    private string $secret;

    public function __construct() {
        $this->url    = config('hamman.ai_service.url');
        $this->secret = config('hamman.ai_service.secret', '');
    }

    public function chat(array $payload): array {
        return $this->post('/ai/chat/complete', $payload);
    }

    public function embedDocument(string $docId, string $chatbotId, string $schema): void {
        $this->post('/ai/embed/document', ['document_id'=>$docId,'chatbot_id'=>$chatbotId,'schema_name'=>$schema]);
    }

    public function healthCheck(): array {
        try {
            return Http::withToken($this->secret)->timeout(5)->get("{$this->url}/ai/health")->json();
        } catch(\Throwable $e) {
            return ['status'=>'down','error'=>$e->getMessage()];
        }
    }

    private function post(string $path, array $data): array {
        try {
            $r = Http::withToken($this->secret)
                     ->timeout(config('hamman.ai_service.timeout', 30))
                     ->retry(config('hamman.ai_service.retry', 2), 500)
                     ->post("{$this->url}{$path}", $data);
            if ($r->failed()) throw new AiServiceException("AI error {$r->status()}: {$r->body()}", $r->status());
            return $r->json() ?? [];
        } catch (AiServiceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AiServiceException('AI service unreachable: ' . $e->getMessage(), 502);
        }
    }
}
