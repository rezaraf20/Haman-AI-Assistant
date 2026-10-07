<?php
namespace App\Services;
use App\Models\Tenant\{Conversation, Message, Chatbot};
use App\Support\WidgetDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Settings;

class ChatService {
    public function __construct(
        private AiGatewayService $ai,
        private TenantService $tenantSvc,
        private QuotaService $quotaSvc,
        private LeadCaptureService $leadCapture,
        private NotificationService $notifications,
    ) {}

    // $language is the CONVERSATION's language (conv.language, set from the
    // widget's own ?lang= at session creation), never $chatbot->language —
    // that column is the merchant's admin-configured default and has no
    // relationship to which language any one visitor is actually chatting
    // in. Every hardcoded-text fallback in this class (quota/error/lead
    // capture) was reading $chatbot->language until this fix, which is why
    // an English-widget visitor could get a Persian system message
    // mid-conversation — not literally two languages concatenated into one
    // string, but the same visible symptom: both languages appearing in one
    // conversation.
    private function quotaExceededMessage(Chatbot $chatbot, string $language): string {
        $key = $language === 'fa' ? 'quota.exceeded_message_fa' : 'quota.exceeded_message_en';
        return $chatbot->fallback_response ?? Settings::get($key);
    }

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

            // A null response means the visitor asked something new instead
            // of giving contact details. Capture has been abandoned; fall
            // through and answer the question like any other.
            if ($lead['response'] !== null) {
                $result = $this->leadResultShape($lead);
                $this->onLeadCaptureOutcome($conv, $chatbot, $lead);
                return $this->finish($conv, $chatbot, $tenant, $result);
            }
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

        $quota = $this->quotaSvc->checkAndReserveTokens($tenant);
        if (!$quota['allowed']) {
            // Skip the AI Gateway call entirely — no cost incurred once a
            // tenant is over their plan's monthly token or message
            // allowance. reservation is 0 here, so finish()'s reconcile is
            // a no-op charge, correctly.
            $result = ['response'=>$this->quotaExceededMessage($chatbot, $conv->language),'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'is_unanswered'=>false,'finish_reason'=>'quota_exceeded'];
        } else {
            try {
                $payload = $this->gatewayPayload($conv, $msg, $chatbot, $tenant, $history);
                if ($quota['degrade']) $payload['llm_model'] = Settings::get('quota.degrade_model_name');
                $result = $this->ai->chat($payload);
            } catch (\Throwable $e) {
                $result = ['response'=>$chatbot->fallback_response??WidgetDefaults::forLanguage($conv->language)['processing_error_response'],'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'is_unanswered'=>false,'finish_reason'=>'error'];
            }
        }

        if ($promptText = $this->leadCapturePromptIfApplicable($conv, $chatbot, $result, $msg)) {
            $result['response'] = $promptText;
            $result['finish_reason'] = 'lead_capture_prompt';
        } elseif ($itemPrompt = $this->leadCaptureItemPromptIfApplicable($conv, $chatbot, $result, $msg)) {
            // Appended, not swapped: the customer still gets the real
            // answer ("that one is out of stock") before the offer.
            $result['response'] = trim($result['response'] . "\n\n" . $itemPrompt);
            $result['finish_reason'] = 'lead_capture_prompt';
        }
        $this->notifyIfUnanswered($chatbot, $result, $msg);

        return $this->finish($conv, $chatbot, $tenant, $result, $quota);
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

            // See the non-streaming path: a null response means this was a
            // new question, not a contact attempt, so it gets answered.
            if ($lead['response'] !== null) {
                $onDelta($lead['response']);
                $result = $this->leadResultShape($lead);
                $this->onLeadCaptureOutcome($conv, $chatbot, $lead);
                return $this->finish($conv, $chatbot, $tenant, $result);
            }
        }

        if (($volunteered = $this->leadCaptureVolunteeredIfApplicable($conv, $chatbot, $msg, $history))) {
            $onDelta($volunteered['response']);
            $result = $this->leadResultShape($volunteered);
            $this->onLeadCaptureOutcome($conv, $chatbot, $volunteered);
            return $this->finish($conv, $chatbot, $tenant, $result);
        }

        $quota = $this->quotaSvc->checkAndReserveTokens($tenant);
        if (!$quota['allowed']) {
            $text = $this->quotaExceededMessage($chatbot, $conv->language);
            $onDelta($text);
            $result = ['response'=>$text,'chunk_ids'=>[],'scores'=>[],'sources'=>[],'prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'model'=>$chatbot->llm_model,'latency_ms'=>0,'is_fallback'=>true,'is_unanswered'=>false,'finish_reason'=>'quota_exceeded'];
        } else {
            try {
                $payload = $this->gatewayPayload($conv, $msg, $chatbot, $tenant, $history);
                if ($quota['degrade']) $payload['llm_model'] = Settings::get('quota.degrade_model_name');
                $result = $this->ai->chatStream($payload, $onDelta);
                if (empty($result)) {
                    // Stream ended without ever sending a "done" event — the
                    // upstream connection dropped mid-stream rather than
                    // raising a catchable exception.
                    throw new \RuntimeException('Stream ended without a done event');
                }
            } catch (\Throwable $e) {
                $text = $chatbot->fallback_response ?? WidgetDefaults::forLanguage($conv->language)['processing_error_response'];
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
        } elseif ($itemPrompt = $this->leadCaptureItemPromptIfApplicable($conv, $chatbot, $result, $msg)) {
            $onDelta("\n\n" . $itemPrompt);
            $result['response'] .= "\n\n" . $itemPrompt;
            $result['finish_reason'] = 'lead_capture_prompt';
        }
        $this->notifyIfUnanswered($chatbot, $result, $msg);

        return $this->finish($conv, $chatbot, $tenant, $result, $quota);
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
        // Always prepended, never left to retrieval — see Chatbot::
        // businessProfilePromptBlock()'s own docblock for why. Prepended
        // (not appended) so it reads as the model's grounding context
        // before any merchant-written instructions that follow it.
        $systemPrompt = $chatbot->businessProfilePromptBlock();
        $systemPrompt = $systemPrompt ? trim($systemPrompt . "\n\n" . ($chatbot->system_prompt ?? '')) : $chatbot->system_prompt;

        return ['chatbot_id'=>$conv->chatbot_id,'conversation_id'=>$conv->id,'session_id'=>$conv->session_id,'query'=>$msg,'history'=>$history,'schema_name'=>$tenant->schema_name,'top_k'=>$chatbot->retrieval_top_k,'threshold'=>$chatbot->retrieval_threshold ?? Settings::get('limits.retrieval_threshold'),'temperature'=>$chatbot->temperature,'max_tokens'=>$chatbot->max_tokens_response,'llm_model'=>$chatbot->llm_model,'language'=>$chatbot->response_language??'auto','system_prompt'=>$systemPrompt,'fallback_response'=>$chatbot->fallback_response,'rerank_enabled'=>$chatbot->reranker_enabled,'rerank_threshold'=>$chatbot->rerank_threshold ?? Settings::get('limits.rerank_threshold'),'business_name'=>$chatbot->business_name,'enabled_tools'=>$chatbot->effectiveTools($tenant),'authenticity_unknown_message'=>$chatbot->authenticity_unknown_message];
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
     * (streaming) the response text with it.
     *
     * is_unanswered is a RETRIEVAL signal only (no indexed chunk scored
     * above threshold) — it says nothing about whether the model actually
     * answered from the business profile instead, which is never
     * retrieved, always injected directly into the system prompt (see
     * Chatbot::businessProfilePromptBlock()). Confirmed by a real
     * production test (2026-10-07, hamantech.ir, "محل شرکت کجاست؟"): the
     * model correctly answered from the profile, and this method still
     * discarded that real answer for the generic lead-capture prompt,
     * because is_unanswered was true regardless. A chatbot with a filled-in
     * profile already has its own "offer known contact instead of a dead
     * end" instruction baked into that block, so once one exists, handing
     * off to lead capture on every profile-covered question is both wrong
     * (throws away a correct answer) and redundant (the profile's own
     * instruction already covers the dead-end case the way point 2 of the
     * business-profile request asked for).
     */
    private function leadCapturePromptIfApplicable(Conversation $conv, Chatbot $chatbot, array $result, string $question): ?string {
        if (!($result['is_unanswered'] ?? false)) return null;
        if ($chatbot->businessProfilePromptBlock() !== null) return null;
        if (!LeadCaptureService::isEnabled($chatbot)) return null;
        return $this->leadCapture->promptForContact($conv, $chatbot, $question);
    }

    /**
     * The out_of_stock / not_in_catalog modes. Unlike the unanswered case,
     * the bot DID answer — it just answered "we can't sell you that right
     * now", which is the moment a shop can still save the sale by offering
     * to call back. The signal comes from the tool call itself
     * (tool_calling_service._detect_lead_signal), not from is_unanswered.
     *
     * Returns null unless the specific mode is switched on for this
     * chatbot: a merchant who wants lead capture for unanswered questions
     * has not thereby asked for a phone number every time something is out
     * of stock.
     */
    private function leadCaptureItemPromptIfApplicable(Conversation $conv, Chatbot $chatbot, array $result, string $question): ?string {
        $signal = $result['lead_signal'] ?? null;
        if (!is_array($signal)) return null;

        $mode = $signal['mode'] ?? null;
        $item = trim((string) ($signal['item'] ?? ''));
        if (!in_array($mode, ['out_of_stock', 'not_in_catalog'], true) || $item === '') return null;

        $productId = isset($signal['product_id']) && is_numeric($signal['product_id'])
            ? (int) $signal['product_id'] : null;

        return $this->leadCapture->promptForItem($conv, $chatbot, $mode, $item, $question, $productId);
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

    private function finish(Conversation $conv, Chatbot $chatbot, object $tenant, array $result, ?array $quota = null): array {
        $assMsg = Message::create(['conversation_id'=>$conv->id,'chatbot_id'=>$conv->chatbot_id,'role'=>'assistant','content'=>$result['response'],'retrieved_chunk_ids'=>$result['chunk_ids']??[],'retrieval_scores'=>$result['scores']??[],'prompt_tokens'=>$result['prompt_tokens']??0,'completion_tokens'=>$result['completion_tokens']??0,'total_tokens'=>$result['total_tokens']??0,'cost_toman'=>$result['cost_toman']??0,'model_used'=>$result['model']??$chatbot->llm_model,'latency_ms'=>$result['latency_ms']??null,'is_fallback'=>$result['is_fallback']??false,'is_unanswered'=>$result['is_unanswered']??false,'created_at'=>now()]);
        $tokens = $result['total_tokens']??0;
        // Synchronous, not dispatch(fn()=>...)->afterResponse(): a raw Closure
        // job doesn't get SerializesModels' rehydration treatment the way a
        // real Job class does (see EmbedDocumentJob), so serializing this
        // closure serializes $tenant's live Eloquent state as-is — fragile,
        // and previously caused a real failure. Deferring it also meant usage
        // could still be unrecorded well after the response went out (process
        // recycled, request aborted).
        //
        // $quota is null for the lead-capture short-circuit paths above
        // (isAwaitingContact/volunteered), which never call
        // checkAndReserveTokens() at all — nothing was reserved, so nothing
        // to reconcile beyond recording the message itself.
        $this->quotaSvc->reconcileUsage($tenant, $quota['bucket'] ?? 'none', $quota['reservation'] ?? 0, $tokens);
        return ['message'=>$assMsg,'result'=>$result];
    }
}
