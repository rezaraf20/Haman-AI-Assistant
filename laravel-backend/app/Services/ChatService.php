<?php
namespace App\Services;
use App\Models\Tenant\{Conversation, Message, Chatbot};
use App\Support\WidgetDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatService {
    public function __construct(
        private AiGatewayService $ai,
        private TenantService $tenantSvc,
        private LeadCaptureService $leadCapture,
        private NotificationService $notifications,
    ) {}

    public function createSession(array $data): Conversation {
        return Conversation::create(['chatbot_id'=>$data['chatbot_id'],'session_id'=>$data['session_id'],'visitor_id'=>$data['visitor_id']??null,'page_url'=>$data['page_url']??null,'language'=>$data['language']??'en','device_type'=>$data['device_type']??'desktop','status'=>'active','started_at'=>now()]);
    }

    public function sendMessage(Conversation $conv, string $msg): array {
        [$chatbot, $tenant, $history] = $this->prepare($conv, $msg);

        // A conversation the bot already asked for contact info on — this
        // message is that attempt, not a new question. Never reaches RAG
        // (a phone number isn't something to retrieve chunks for) and
        // costs nothing.
        if ($this->leadCapture->isAwaitingContact($conv)) {
            $lead = $this->leadCapture->handleContactAttempt($conv, $chatbot, $msg);
            $result = $this->leadResultShape($lead);
            $this->onLeadCaptureOutcome($conv, $chatbot, $lead);
            return $this->finish($conv, $chatbot, $tenant, $result);
        }

        // A customer volunteering their number/email unprompted — "ثبت کن،
        // تماس بگیرید 0937..." — is the warmest lead possible, and a real
        // production incident showed it silently reaching RAG instead: the
        // bot answered "that number isn't in our information". Checked
        // regardless of pending_lead_question (this is a *different*
        // trigger, not the two-turn flow above) and, like that flow, never
        // reaches RAG.
        if (($volunteered = $this->leadCaptureVolunteeredIfApplicable($conv, $chatbot, $msg, $history))) {
            $result = $this->leadResultShape($volunteered);
            $this->onLeadCaptureOutcome($conv, $chatbot, $volunteered);
            return $this->finish($conv, $chatbot, $tenant, $result);
        }

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

        if ($promptText = $this->leadCapturePromptIfApplicable($conv, $chatbot, $result, $msg)) {
            $result['response'] = $promptText;
            $result['finish_reason'] = 'lead_capture_prompt';
        }
        $this->notifyIfUnanswered($chatbot, $result, $msg);

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

        if ($this->leadCapture->isAwaitingContact($conv)) {
            $lead = $this->leadCapture->handleContactAttempt($conv, $chatbot, $msg);
            $onDelta($lead['response']);
            $result = $this->leadResultShape($lead);
            $this->onLeadCaptureOutcome($conv, $chatbot, $lead);
            return $this->finish($conv, $chatbot, $tenant, $result);
        }

        if (($volunteered = $this->leadCaptureVolunteeredIfApplicable($conv, $chatbot, $msg, $history))) {
            $onDelta($volunteered['response']);
            $result = $this->leadResultShape($volunteered);
            $this->onLeadCaptureOutcome($conv, $chatbot, $volunteered);
            return $this->finish($conv, $chatbot, $tenant, $result);
        }

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

        // By the time is_unanswered is known here, whatever text the AI
        // gateway already streamed via $onDelta is already flushed to the
        // client — there's no un-sending it. The lead-capture prompt can
        // only be *appended* as a second chunk, not swapped in as a
        // replacement the way sendMessage() can do before anything's been
        // sent at all.
        if ($promptText = $this->leadCapturePromptIfApplicable($conv, $chatbot, $result, $msg)) {
            $onDelta("\n\n" . $promptText);
            $result['response'] .= "\n\n" . $promptText;
            $result['finish_reason'] = 'lead_capture_prompt';
        }
        $this->notifyIfUnanswered($chatbot, $result, $msg);

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
        return ['chatbot_id'=>$conv->chatbot_id,'conversation_id'=>$conv->id,'session_id'=>$conv->session_id,'query'=>$msg,'history'=>$history,'schema_name'=>$tenant->schema_name,'top_k'=>$chatbot->retrieval_top_k,'threshold'=>$chatbot->retrieval_threshold,'temperature'=>$chatbot->temperature,'max_tokens'=>$chatbot->max_tokens_response,'llm_model'=>$chatbot->llm_model,'language'=>$chatbot->response_language??'auto','system_prompt'=>$chatbot->system_prompt,'fallback_response'=>$chatbot->fallback_response,'rerank_enabled'=>$chatbot->reranker_enabled,'rerank_threshold'=>$chatbot->rerank_threshold,'business_name'=>$chatbot->business_name];
    }

    /** Returns a handleVolunteeredContact()-shaped lead result if $msg
     * contains contact info the customer volunteered unprompted, or null
     * if it doesn't apply (lead capture isn't enabled for this chatbot, or
     * no phone/email was found in the message at all). Gated behind the
     * same opt-in flag as the rest of lead capture — a chatbot that never
     * turned this feature on shouldn't start silently intercepting any
     * message that happens to contain a phone number. */
    private function leadCaptureVolunteeredIfApplicable(Conversation $conv, Chatbot $chatbot, string $msg, array $history): ?array {
        if (!LeadCaptureService::isEnabled($chatbot)) return null;
        $parsed = $this->leadCapture->extractContact($msg);
        if (!$parsed) return null;
        return $this->leadCapture->handleVolunteeredContact($conv, $chatbot, $msg, $parsed, $history);
    }

    /** Returns the lead-capture prompt text if this response should trigger
     * asking for contact info (chatbot has lead capture enabled AND the
     * result came back unanswered), or null if it doesn't apply — the
     * caller decides whether to replace (non-streaming) or append
     * (streaming) the response text with it. */
    private function leadCapturePromptIfApplicable(Conversation $conv, Chatbot $chatbot, array $result, string $question): ?string {
        if (!($result['is_unanswered'] ?? false)) return null;
        if (!LeadCaptureService::isEnabled($chatbot)) return null;
        return $this->leadCapture->promptForContact($conv, $chatbot, $question);
    }

    /** @return array{response:string,chunk_ids:array,scores:array,prompt_tokens:int,completion_tokens:int,total_tokens:int,cost_toman:int,model:string,latency_ms:int,is_fallback:bool,is_unanswered:bool,finish_reason:string} */
    private function leadResultShape(array $lead): array {
        return ['response'=>$lead['response'],'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'cost_toman'=>0,'model'=>'n/a','latency_ms'=>0,'is_fallback'=>false,'is_unanswered'=>false,'finish_reason'=>$lead['finish_reason']];
    }

    /** Logs the lead_captured event and fires notifications once a contact
     * attempt actually resulted in a saved Lead row — not on an invalid
     * attempt, which just re-prompts and produces neither. */
    private function onLeadCaptureOutcome(Conversation $conv, Chatbot $chatbot, array $lead): void {
        if (($lead['finish_reason'] ?? null) !== 'lead_captured' || empty($lead['lead'])) return;
        $record = $lead['lead'];

        DB::table('conversation_events')->insert([
            'id'              => (string) Str::uuid(),
            'conversation_id' => $conv->id,
            'chatbot_id'      => $conv->chatbot_id,
            'event_type'      => 'lead_captured',
            'payload'         => json_encode(['contact' => $record->contact, 'contact_type' => $record->contact_type, 'reason' => $record->question]),
            'created_at'      => now(),
        ]);

        $this->notifications->send($chatbot, 'lead_captured', [
            'contact'      => $record->contact,
            'contact_type' => $record->contact_type,
            'question'     => $record->question,
        ]);
    }

    private function notifyIfUnanswered(Chatbot $chatbot, array $result, string $query): void {
        if (!($result['is_unanswered'] ?? false)) return;
        $this->notifications->send($chatbot, 'unanswered', ['query' => $query]);
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
