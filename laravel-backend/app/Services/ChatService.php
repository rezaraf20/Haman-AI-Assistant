<?php
namespace App\Services;
use App\Models\Tenant\{Conversation, Message, Chatbot};
use App\Support\WidgetDefaults;

class ChatService {
    public function __construct(private AiGatewayService $ai, private TenantService $tenantSvc) {}

    public function createSession(array $data): Conversation {
        return Conversation::create(['chatbot_id'=>$data['chatbot_id'],'session_id'=>$data['session_id'],'visitor_id'=>$data['visitor_id']??null,'page_url'=>$data['page_url']??null,'language'=>$data['language']??'en','device_type'=>$data['device_type']??'desktop','status'=>'active','started_at'=>now()]);
    }

    public function sendMessage(Conversation $conv, string $msg): array {
        [$chatbot, $tenant, $history] = $this->prepare($conv, $msg);

        if ($tenant->isTokenQuotaExceeded()) {
            // Skip the AI Gateway call entirely — no cost incurred once a
            // tenant is over their plan's monthly token allowance.
            $result = ['response'=>$chatbot->fallback_response??WidgetDefaults::forLanguage($chatbot->language)['quota_exceeded_response'],'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'is_unanswered'=>false,'finish_reason'=>'quota_exceeded'];
        } else {
            try {
                $result = $this->ai->chat($this->gatewayPayload($conv, $msg, $chatbot, $tenant, $history));
            } catch (\Throwable $e) {
                $result = ['response'=>$chatbot->fallback_response??WidgetDefaults::forLanguage($chatbot->language)['processing_error_response'],'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'is_unanswered'=>false,'finish_reason'=>'error'];
            }
        }

        return $this->finish($conv, $chatbot, $tenant, $result);
    }

    /**
     * Streaming counterpart to sendMessage() — same persistence, quota-check,
     * and history logic, but calls the AI gateway's streaming method instead
     * of the blocking one, invoking $onDelta(string $text) as tokens arrive.
     * The assistant Message row is still only persisted once, after the
     * stream (or the quota-exceeded fallback) has fully resolved — same as
     * the non-streaming path, so both write exactly one row per reply.
     */
    public function sendMessageStream(Conversation $conv, string $msg, callable $onDelta): array {
        [$chatbot, $tenant, $history] = $this->prepare($conv, $msg);

        if ($tenant->isTokenQuotaExceeded()) {
            $text = $chatbot->fallback_response ?? WidgetDefaults::forLanguage($chatbot->language)['quota_exceeded_response'];
            $onDelta($text);
            $result = ['response'=>$text,'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'is_unanswered'=>false,'finish_reason'=>'quota_exceeded'];
        } else {
            try {
                $result = $this->ai->chatStream($this->gatewayPayload($conv, $msg, $chatbot, $tenant, $history), $onDelta);
                if (empty($result)) {
                    // Stream ended without ever sending a "done" event — the
                    // upstream connection dropped mid-stream rather than
                    // raising a catchable exception.
                    throw new \RuntimeException('Stream ended without a done event');
                }
            } catch (\Throwable $e) {
                $text = $chatbot->fallback_response ?? WidgetDefaults::forLanguage($chatbot->language)['processing_error_response'];
                $onDelta($text);
                $result = ['response'=>$text,'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'is_unanswered'=>false,'finish_reason'=>'error'];
            }
        }

        return $this->finish($conv, $chatbot, $tenant, $result);
    }

    /** @return array{0:Chatbot,1:object,2:array} [$chatbot, $tenant, $history] */
    private function prepare(Conversation $conv, string $msg): array {
        if ($conv->status !== 'active') throw new \RuntimeException('Conversation not active');
        $chatbot = Chatbot::findOrFail($conv->chatbot_id);
        Message::create(['conversation_id'=>$conv->id,'chatbot_id'=>$conv->chatbot_id,'role'=>'user','content'=>$msg,'total_tokens'=>0,'created_at'=>now()]);
        $history = Message::where('conversation_id',$conv->id)->orderBy('created_at','desc')->limit($chatbot->memory_window*2)->get()->reverse()->values()->map(fn($m)=>['role'=>$m->role,'content'=>$m->content])->toArray();
        $tenant = app('current_tenant');
        return [$chatbot, $tenant, $history];
    }

    private function gatewayPayload(Conversation $conv, string $msg, Chatbot $chatbot, object $tenant, array $history): array {
        return ['chatbot_id'=>$conv->chatbot_id,'session_id'=>$conv->session_id,'query'=>$msg,'history'=>$history,'schema_name'=>$tenant->schema_name,'top_k'=>$chatbot->retrieval_top_k,'threshold'=>$chatbot->retrieval_threshold,'temperature'=>$chatbot->temperature,'max_tokens'=>$chatbot->max_tokens_response,'llm_model'=>$chatbot->llm_model,'language'=>$chatbot->response_language??'auto','system_prompt'=>$chatbot->system_prompt,'fallback_response'=>$chatbot->fallback_response];
    }

    private function finish(Conversation $conv, Chatbot $chatbot, object $tenant, array $result): array {
        $assMsg = Message::create(['conversation_id'=>$conv->id,'chatbot_id'=>$conv->chatbot_id,'role'=>'assistant','content'=>$result['response'],'retrieved_chunk_ids'=>$result['chunk_ids']??[],'retrieval_scores'=>$result['scores']??[],'prompt_tokens'=>$result['prompt_tokens']??0,'completion_tokens'=>$result['completion_tokens']??0,'total_tokens'=>$result['total_tokens']??0,'cost_toman'=>$result['cost_toman']??0,'model_used'=>$result['model']??$chatbot->llm_model,'latency_ms'=>$result['latency_ms']??null,'is_fallback'=>$result['is_fallback']??false,'is_unanswered'=>$result['is_unanswered']??false,'created_at'=>now()]);
        $tokens = $result['total_tokens']??0;
        // Synchronous, not dispatch(fn()=>...)->afterResponse(): a raw Closure
        // job doesn't get SerializesModels' rehydration treatment the way a
        // real Job class does (see EmbedDocumentJob), so serializing this
        // closure serializes $tenant's live Eloquent state as-is — fragile,
        // and previously caused a real failure. Deferring it also meant usage
        // could still be unrecorded well after the response went out (process
        // recycled, request aborted) and widened the window where concurrent
        // requests all read a stale usage_tokens_current before any of their
        // increments landed. Doing it inline still isn't a hard lock around
        // the quota check, but it collapses that window to just this request.
        $this->tenantSvc->incrementUsage($tenant,$tokens);
        return ['message'=>$assMsg,'result'=>$result];
    }
}
