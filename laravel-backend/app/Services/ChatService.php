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
        if ($conv->status !== 'active') throw new \RuntimeException('Conversation not active');
        $chatbot = Chatbot::findOrFail($conv->chatbot_id);
        Message::create(['conversation_id'=>$conv->id,'chatbot_id'=>$conv->chatbot_id,'role'=>'user','content'=>$msg,'total_tokens'=>0,'created_at'=>now()]);
        $history = Message::where('conversation_id',$conv->id)->orderBy('created_at','desc')->limit($chatbot->memory_window*2)->get()->reverse()->values()->map(fn($m)=>['role'=>$m->role,'content'=>$m->content])->toArray();
        $tenant  = app('current_tenant');
        if ($tenant->isTokenQuotaExceeded()) {
            // Skip the AI Gateway call entirely — no cost incurred once a
            // tenant is over their plan's monthly token allowance.
            $result = ['response'=>$chatbot->fallback_response??WidgetDefaults::forLanguage($chatbot->language)['quota_exceeded_response'],'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'finish_reason'=>'quota_exceeded'];
        } else {
            try {
                $result = $this->ai->chat(['chatbot_id'=>$conv->chatbot_id,'session_id'=>$conv->session_id,'query'=>$msg,'history'=>$history,'schema_name'=>$tenant->schema_name,'top_k'=>$chatbot->retrieval_top_k,'threshold'=>$chatbot->retrieval_threshold,'temperature'=>$chatbot->temperature,'max_tokens'=>$chatbot->max_tokens_response,'llm_model'=>$chatbot->llm_model,'language'=>$chatbot->response_language??'auto','system_prompt'=>$chatbot->system_prompt,'fallback_response'=>$chatbot->fallback_response]);
            } catch (\Throwable $e) {
                $result = ['response'=>$chatbot->fallback_response??WidgetDefaults::forLanguage($chatbot->language)['processing_error_response'],'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'finish_reason'=>'error'];
            }
        }
        $assMsg = Message::create(['conversation_id'=>$conv->id,'chatbot_id'=>$conv->chatbot_id,'role'=>'assistant','content'=>$result['response'],'retrieved_chunk_ids'=>$result['chunk_ids']??[],'retrieval_scores'=>$result['scores']??[],'prompt_tokens'=>$result['prompt_tokens']??0,'completion_tokens'=>$result['completion_tokens']??0,'total_tokens'=>$result['total_tokens']??0,'cost_toman'=>$result['cost_toman']??0,'model_used'=>$result['model']??$chatbot->llm_model,'latency_ms'=>$result['latency_ms']??null,'is_fallback'=>$result['is_fallback']??false,'created_at'=>now()]);
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
