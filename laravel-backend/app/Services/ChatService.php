<?php
namespace App\Services;
use App\Models\Tenant\{Conversation, Message, Chatbot};

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
        try {
            $result = $this->ai->chat(['chatbot_id'=>$conv->chatbot_id,'session_id'=>$conv->session_id,'query'=>$msg,'history'=>$history,'schema_name'=>$tenant->schema_name,'top_k'=>$chatbot->retrieval_top_k,'threshold'=>$chatbot->retrieval_threshold,'temperature'=>$chatbot->temperature,'max_tokens'=>$chatbot->max_tokens_response,'llm_model'=>$chatbot->llm_model,'language'=>$chatbot->response_language??'auto','system_prompt'=>$chatbot->system_prompt,'fallback_response'=>$chatbot->fallback_response]);
        } catch (\Throwable $e) {
            $result = ['response'=>$chatbot->fallback_response??'Sorry, I could not process your request.','chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'finish_reason'=>'error'];
        }
        $assMsg = Message::create(['conversation_id'=>$conv->id,'chatbot_id'=>$conv->chatbot_id,'role'=>'assistant','content'=>$result['response'],'retrieved_chunk_ids'=>$result['chunk_ids']??[],'retrieval_scores'=>$result['scores']??[],'prompt_tokens'=>$result['prompt_tokens']??0,'completion_tokens'=>$result['completion_tokens']??0,'total_tokens'=>$result['total_tokens']??0,'model_used'=>$result['model']??$chatbot->llm_model,'latency_ms'=>$result['latency_ms']??null,'is_fallback'=>$result['is_fallback']??false,'created_at'=>now()]);
        $tokens = $result['total_tokens']??0;
        dispatch(fn()=>$this->tenantSvc->incrementUsage($tenant,$tokens))->afterResponse();
        return ['message'=>$assMsg,'result'=>$result];
    }
}
