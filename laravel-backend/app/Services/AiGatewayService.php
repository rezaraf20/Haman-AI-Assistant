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

    /**
     * SSE counterpart to chat() — calls $onDelta(string $text) for each token
     * fragment as it arrives from the Python service's /ai/chat/stream, and
     * returns the final "done" event's payload (same fields chat()'s return
     * array has) once the stream ends. Uses its own, longer timeout
     * (hamman.ai_service.stream_timeout, not the regular request timeout) —
     * a streamed answer's total wall time can genuinely exceed a normal
     * request's timeout even though bytes are arriving the whole time.
     */
    public function chatStream(array $payload, callable $onDelta): array {
        try {
            $response = Http::withToken($this->secret)
                ->withOptions(['stream' => true])
                ->timeout(config('hamman.ai_service.stream_timeout', 120))
                ->post("{$this->url}/ai/chat/stream", $payload);
        } catch (\Throwable $e) {
            throw new AiServiceException('AI service unreachable: ' . $e->getMessage(), 502);
        }

        if ($response->failed()) {
            throw new AiServiceException("AI stream error {$response->status()}: {$response->body()}", $response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $done = null;

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') continue;
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                [$event, $data] = $this->parseSseFrame($frame);
                if ($data === null) continue;
                $decoded = json_decode($data, true);
                if (!is_array($decoded)) continue;
                if ($event === 'done') {
                    $done = $decoded;
                } elseif (isset($decoded['delta'])) {
                    $onDelta($decoded['delta']);
                }
            }
        }

        return $done ?? [];
    }

    /** @return array{0:string,1:?string} [$eventName, $rawDataString] */
    private function parseSseFrame(string $frame): array {
        $event = 'message';
        $data = null;
        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $piece = ltrim(substr($line, 5));
                $data = $data === null ? $piece : ($data . "\n" . $piece);
            }
        }
        return [$event, $data];
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
