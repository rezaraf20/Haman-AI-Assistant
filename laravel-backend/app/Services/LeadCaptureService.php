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
            // A visitor who answers the contact request with another
            // question has not mistyped a phone number; they have moved on.
            // Telling them their question "doesn't look like a valid phone
            // number" and staying armed made every later message get the
            // same reply, because nothing here ever cleared the pending
            // state -- a real conversation went round that loop until the
            // visitor gave up. So: give up asking, and let the question be
            // answered.
            if (!$this->looksLikeContactAttempt($message)) {
                $this->clearPending($conv);

                return [
                    'response'      => null,
                    'finish_reason' => 'lead_capture_abandoned',
                    'lead'          => null,
                ];
            }

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
            'requested_item'  => $conv->pending_lead_item,
            'requested_product_id' => $conv->pending_lead_product_id,
            'type'            => $conv->pending_lead_type ?: 'unanswered',
            'status'          => 'new',
        ]);

        $this->clearPending($conv);

        // The thank-you differs by mode: promising to text someone when a
        // product is restocked is a commitment the shop can keep, and the
        // same sentence would be a lie for something it has never carried.
        $thanks = match ($lead->type) {
            'out_of_stock'    => $texts['lead_capture_out_of_stock_thanks'] ?? $texts['lead_capture_thanks'],
            'not_in_catalog'  => $texts['lead_capture_not_in_catalog_thanks'] ?? $texts['lead_capture_thanks'],
            default           => $texts['lead_capture_thanks'],
        };

        return [
            'response'      => $thanks,
            'finish_reason' => 'lead_captured',
            'lead'          => $lead,
        ];
    }

    /**
     * The two product-driven modes (doc "out_of_stock" / "not_in_catalog").
     * Called when a tool call showed the customer wanted something the shop
     * cannot sell them right now — see tool_calling_service._detect_lead_signal()
     * for where the signal is produced.
     *
     * Returns the sentence to append to the bot's own answer, or null when
     * this mode is switched off for this chatbot. Appended rather than
     * replacing: the customer still deserves the real answer ("that one is
     * out of stock") before being offered a callback.
     */
    public function promptForItem(Conversation $conv, Chatbot $chatbot, string $mode, string $item, string $question, ?int $productId = null): ?string {
        if (!self::isModeEnabled($chatbot, $mode)) return null;

        $texts = array_merge(WidgetDefaults::forLanguage($chatbot->language), $chatbot->widget_config ?? []);
        $key = $mode === 'out_of_stock' ? 'lead_capture_out_of_stock_prompt' : 'lead_capture_not_in_catalog_prompt';

        $conv->update([
            'pending_lead_question'   => $question,
            'pending_lead_type'       => $mode,
            'pending_lead_item'       => mb_substr($item, 0, 255),
            'pending_lead_product_id' => $productId,
        ]);

        return str_replace(':item', $item, $texts[$key]);
    }

    /**
     * Each mode is switched off independently, and both are off unless the
     * merchant turns them on — a shop that does not want a phone number
     * collected every time something is out of stock must not get one just
     * because it enabled lead capture for unanswered questions.
     */
    public static function isModeEnabled(Chatbot $chatbot, string $mode): bool {
        if (!self::isEnabled($chatbot)) return false;
        $key = $mode === 'out_of_stock'
            ? 'lead_capture_out_of_stock_enabled'
            : 'lead_capture_not_in_catalog_enabled';
        return (bool) ($chatbot->widget_config[$key] ?? false);
    }

    /** Called when a fresh (non-contact-attempt) query comes back
     * is_unanswered=true and the chatbot has lead capture enabled — swaps
     * the plain fallback text for a contact-info request and arms the
     * conversation's pending_lead_question for the next turn. */
    public function promptForContact(Conversation $conv, Chatbot $chatbot, string $question): ?string {
        // Once per conversation. A visitor who was asked and carried on
        // asking questions has declined; asking again after every unanswered
        // message is what one of them described as being nagged for their
        // number. Null means "say the ordinary fallback instead".
        if ($conv->lead_capture_asked_at !== null) {
            return null;
        }

        $texts = array_merge(WidgetDefaults::forLanguage($chatbot->language), $chatbot->widget_config ?? []);
        $conv->update([
            'pending_lead_question' => $question,
            'lead_capture_asked_at' => now(),
        ]);
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
     *
     * Only for the two-turn flow's second turn, where the *entire* message
     * is expected to be contact info — see extractContact() for scanning a
     * free-form message that merely contains one.
     */
    /**
     * Whether this message is someone trying to give contact details at all.
     *
     * Deliberately generous: anything with an "@" or a run of digits long
     * enough to be a phone number counts as an attempt, so a genuine typo
     * still gets the "that doesn't look right" reply and another chance.
     * Everything else -- "خدماتتون چیه؟", "الو", "قیمت میدی؟" -- is a
     * question, and answering it beats correcting it.
     */
    private function looksLikeContactAttempt(string $message): bool {
        if (str_contains($message, '@')) {
            return true;
        }

        $digits = preg_replace('/\D/', '', $message) ?? '';

        // Shorter than this and it is a word with a number in it, not a
        // phone number someone fumbled.
        return mb_strlen($digits) >= 7;
    }

    private function clearPending(Conversation $conv): void {
        $conv->update([
            'pending_lead_question'   => null,
            'pending_lead_type'       => null,
            'pending_lead_item'       => null,
            'pending_lead_product_id' => null,
        ]);
    }

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

    /**
     * Scans a free-form message for a phone number or email ANYWHERE
     * within it — a customer volunteering "شمارمو ثبت کن، تماس بگیرید
     * 09371234567" mid-sentence, never having been asked, is the strongest
     * lead signal there is; letting that sentence fall through to RAG
     * instead (which is what happened before this existed — the bot
     * literally answered "the number ... isn't in our information") is
     * exactly the bug this fixes.
     *
     * Deliberately stricter than parseContact() on the Iranian-mobile
     * pattern: an explicit prefix (0 / +98 / 0098) is required here, since
     * scanning arbitrary free text for a bare 9-digit run starting with 9
     * risks matching an unrelated number (an order code, a price) inside
     * an otherwise ordinary sentence — a risk parseContact() doesn't have,
     * because there the *entire* message is already expected to be contact
     * info by the time it's called.
     */
    public function extractContact(string $message): ?array {
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $message, $m)) {
            return ['contact' => $m[0], 'contact_type' => 'email'];
        }

        if (preg_match('/(?:\+98|0098|0)[\s\-]?9(?:[\s\-]?\d){9}/', $message, $m)) {
            $digits = preg_replace('/[\s\-]/', '', $m[0]);
            $normalized = '0' . preg_replace('/^(?:\+98|0098|0)/', '', $digits);
            return ['contact' => $normalized, 'contact_type' => 'phone'];
        }

        if (preg_match('/\+[1-9]\d{7,14}/', $message, $m)) {
            return ['contact' => $m[0], 'contact_type' => 'phone'];
        }

        return null;
    }

    /**
     * The customer volunteers contact info without ever being asked
     * (pending_lead_question was never set) — captured immediately,
     * never routed through RAG. Unlike handleContactAttempt(), there's no
     * pending_lead_question to reuse as the sales-relevant "question", so
     * $recentHistory (the conversation history already built by
     * ChatService::prepare(), current message included) is used to build
     * one instead.
     *
     * @return array{response: string, finish_reason: string, lead: ?Lead}
     */
    public function handleVolunteeredContact(Conversation $conv, Chatbot $chatbot, string $message, array $parsed, array $recentHistory): array {
        $texts = array_merge(WidgetDefaults::forLanguage($chatbot->language), $chatbot->widget_config ?? []);

        $lead = Lead::create([
            'conversation_id' => $conv->id,
            'chatbot_id'      => $conv->chatbot_id,
            'contact'         => $parsed['contact'],
            'contact_type'    => $parsed['contact_type'],
            'question'        => $this->summarizeRecentHistory($recentHistory) ?? $message,
            'type'            => 'volunteered',
            'status'          => 'new',
        ]);

        return [
            'response'      => $texts['lead_capture_thanks'],
            'finish_reason' => 'lead_captured',
            'lead'          => $lead,
        ];
    }

    /**
     * Best-effort context for whoever follows up on the lead, when there's
     * no pending_lead_question to attach — the last few prior *user* turns,
     * concatenated. Not an LLM summary: this class has no Python/LLM
     * involvement by design (see the class docstring), and a cheap,
     * synchronous concatenation is good enough to tell a salesperson what
     * the conversation was about. Excludes the current message itself
     * (already stored verbatim as $message / the fallback below it).
     */
    private function summarizeRecentHistory(array $history): ?string {
        $userTurns = array_values(array_filter($history, fn ($h) => ($h['role'] ?? null) === 'user'));
        array_pop($userTurns); // the just-created current message — not "prior" context
        $recent = array_slice($userTurns, -3);
        $texts = array_filter(array_map(fn ($h) => trim($h['content'] ?? ''), $recent));
        $joined = trim(implode(' / ', $texts));
        return $joined !== '' ? $joined : null;
    }
}
