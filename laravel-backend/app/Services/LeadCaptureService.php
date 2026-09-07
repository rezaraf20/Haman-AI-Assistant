<?php
namespace App\Services;

use App\Models\Tenant\{Chatbot, Conversation, Lead};
use App\Support\WidgetDefaults;

/**
 * A missed answer with no way to reach the visitor back is a lost sale for
 * a B2B/high-ticket shop, not just an unanswered-rate number on a
 * dashboard. Opt-in per chatbot (widget_config.lead_capture_enabled) —
 * existing chatbots keep today's plain fallback_response behavior
 * unchanged unless the merchant turns this on.
 *
 * Two-turn state machine, entirely server-side, no Python involvement:
 *   1. A query comes back is_unanswered=true. Instead of the plain
 *      fallback text, respond asking for contact info and set
 *      conversations.pending_lead_question to the question that triggered
 *      it (see ChatService::finish()).
 *   2. The *next* message on that conversation is read as a contact-info
 *      attempt (this class), never run through RAG at all — a phone
 *      number or email address is not a question to retrieve chunks for.
 */
class LeadCaptureService {
    public function isAwaitingContact(Conversation $conv): bool {
        return filled($conv->pending_lead_question);
    }

    /** @return array{response: string, finish_reason: string, lead: ?Lead} */
    public function handleContactAttempt(Conversation $conv, Chatbot $chatbot, string $message): array {
        $texts = array_merge(WidgetDefaults::forLanguage($chatbot->language), $chatbot->widget_config ?? []);
        $parsed = $this->parseContact($message);

        if (!$parsed) {
            return [
                'response'      => $texts['lead_capture_invalid'],
                'finish_reason' => 'lead_capture_invalid',
                'lead'          => null,
            ];
        }

        $lead = Lead::create([
            'conversation_id' => $conv->id,
            'chatbot_id'      => $conv->chatbot_id,
            'contact'         => $parsed['contact'],
            'contact_type'    => $parsed['contact_type'],
            'question'        => $conv->pending_lead_question,
            'status'          => 'new',
        ]);

        $conv->update(['pending_lead_question' => null]);

        return [
            'response'      => $texts['lead_capture_thanks'],
            'finish_reason' => 'lead_captured',
            'lead'          => $lead,
        ];
    }

    /** Called when a fresh (non-contact-attempt) query comes back
     * is_unanswered=true and the chatbot has lead capture enabled — swaps
     * the plain fallback text for a contact-info request and arms the
     * conversation's pending_lead_question for the next turn. */
    public function promptForContact(Conversation $conv, Chatbot $chatbot, string $question): string {
        $texts = array_merge(WidgetDefaults::forLanguage($chatbot->language), $chatbot->widget_config ?? []);
        $conv->update(['pending_lead_question' => $question]);
        return $texts['lead_capture_prompt'];
    }

    public static function isEnabled(Chatbot $chatbot): bool {
        return (bool) ($chatbot->widget_config['lead_capture_enabled'] ?? false);
    }

    /**
     * Accepts an email, or a phone number — Iranian (09xxxxxxxxx,
     * +989xxxxxxxxx, 00989xxxxxxxxx) or general international (+ followed
     * by 8-15 digits, loosely E.164). Returns null for anything else
     * (including a plain question re-asked instead of contact info, which
     * is exactly the case that must fall through to lead_capture_invalid
     * rather than silently being treated as a valid contact).
     */
    public function parseContact(string $input): ?array {
        $input = trim($input);
        if ($input === '') return null;

        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return ['contact' => $input, 'contact_type' => 'email'];
        }

        $digits = preg_replace('/[\s\-()]/', '', $input);

        if (preg_match('/^(?:\+98|0098|0)?9\d{9}$/', $digits)) {
            $normalized = '0' . preg_replace('/^(?:\+98|0098|0)/', '', $digits);
            return ['contact' => $normalized, 'contact_type' => 'phone'];
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $digits)) {
            return ['contact' => $digits, 'contact_type' => 'phone'];
        }

        return null;
    }
}
