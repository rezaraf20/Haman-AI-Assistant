<?php
namespace App\Http\Controllers\Api\V1;

use App\Services\ChatService;
use App\Services\PaymentLinkService;
use App\Services\OrderStatusService;
use App\Services\SmsService;
use App\Services\WalletService;
use App\Models\OrderStatusOtp;
use App\Models\PlatformSetting;
use App\Models\Tenant\{Chatbot, Conversation};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Support\WidgetDefaults;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends BaseApiController
{
    // create_payment_link (doc-04) security caps — deliberately hardcoded
    // rather than admin-configurable: the task's explicit "don't build
    // this without" rules ask for these limits to exist and actually be
    // enforced, not for a settings UI to tune them. max_payment_link_amount
    // is the one cap the task explicitly calls out as per-chatbot
    // configurable — that one lives on the chatbots table instead.
    private const MAX_PAYMENT_LINKS_PER_CONVERSATION = 3;
    private const MAX_PAYMENT_LINKS_PER_IP_PER_DAY = 5;

    // get_order_status (doc-04). Every one of these is an anti-abuse
    // control first and a UX limit second: an OTP flow reachable from an
    // anonymous public chat widget, that spends the MERCHANT's money per
    // send, is exactly the shape of thing that gets turned into a free
    // SMS-harassment tool if any of them is missing.
    private const MAX_CODES_PER_CONTACT_PER_HOUR = 3;
    private const MAX_CODES_PER_CHATBOT_PER_DAY = 100;
    private const MAX_CODE_REQUESTS_PER_IP_PER_DAY = 20;
    private const ORDER_STATUS_CODE_TTL_MINUTES = 5;
    private const MAX_ORDER_STATUS_VERIFY_ATTEMPTS = 3;

    public function __construct(
        private ChatService $svc,
        private PaymentLinkService $paymentLinks,
        private OrderStatusService $orderStatus,
    ) {}

    // $preResolved lets callers reuse the chatbot_index row
    // ValidateChatbotDomain already looked up and stashed on the request
    // (see $request->attributes->get('chatbot_index')) instead of querying
    // it again here.
    private function setSchemaFromChatbot(string $chatbotId, ?object $preResolved = null): ?object
    {
        $index = $preResolved ?? DB::table('chatbot_index')
            ->where('chatbot_id', $chatbotId)
            ->where('is_active', true)
            ->first();
        if (!$index) return null;
        DB::statement("SET search_path TO {$index->schema_name}, public");
        $tenant = \App\Models\Tenant::find($index->tenant_id);
        if ($tenant) app()->instance('current_tenant', $tenant);
        return $index;
    }

    // Widget UI text (send button, placeholder, error messages, welcome
    // message, chat title, AI name, quick questions, system instruction,
    // avatar) — see App\Support\WidgetDefaults::merge(), the single place
    // that produces this, shared with ChatbotController (WordPress plugin's
    // read-only display / customer portal's WidgetSettings page). Previously
    // createSession() read chatbots.welcome_message directly with no
    // fallback at all when it was empty — a chatbot that had never had it
    // set (e.g. created directly in the customer portal, no WordPress
    // plugin push yet) showed no welcome message whatsoever.
    private function mergedWidgetConfig(Chatbot $chatbot): array {
        return WidgetDefaults::merge($chatbot);
    }

    public function createSession(Request $req): JsonResponse
    {
        $d = $req->validate([
            'chatbot_id' => 'required|uuid',
            'session_id' => 'required|string|max:128',
            'visitor_id' => 'nullable|string|max:128',
            'page_url'   => 'nullable|string|max:2000',
            'language'   => 'nullable|string|max:10',
        ]);

        $index = $this->setSchemaFromChatbot($d['chatbot_id'], $req->attributes->get('chatbot_index'));
        if (!$index) return $this->notFound('Chatbot not found');

        $chatbot = Chatbot::where('id', $d['chatbot_id'])->where('is_active', true)->first();
        if (!$chatbot) return $this->notFound('Chatbot not found or inactive');

        $existing = Conversation::where('chatbot_id', $d['chatbot_id'])
            ->where('session_id', $d['session_id'])
            ->first();
        if ($existing) {
            $merged = $this->mergedWidgetConfig($chatbot);
            return $this->ok([
                'conversation_id' => $existing->id,
                'session_id'      => $existing->session_id,
                'welcome_message' => $merged['welcome_message'],
                'language'        => $chatbot->language,
                'widget_config'   => $merged,
            ]);
        }

        $ua   = $req->userAgent() ?? '';
        $conv = Conversation::create([
            'chatbot_id'  => $d['chatbot_id'],
            'session_id'  => $d['session_id'],
            'visitor_id'  => $d['visitor_id'] ?? null,
            'page_url'    => $d['page_url'] ?? null,
            'language'    => $d['language'] ?? 'en',
            'device_type' => preg_match('/Mobile|Android|iPhone/i', $ua) ? 'mobile' : 'desktop',
            'status'      => 'active',
            'started_at'  => now(),
        ]);

        // Only for a genuinely new conversation — the $existing branch above
        // returns early for a repeat /chat/session call against the same
        // session_id, which must not log a second conversation_started for
        // the same conversation.
        DB::table('conversation_events')->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $conv->id,
            'chatbot_id'      => $conv->chatbot_id,
            'event_type'      => 'conversation_started',
            // Only one channel exists today (the WordPress widget) — a
            // fixed value rather than an unused parameter, honest about
            // there being nothing else to distinguish yet.
            'payload'         => json_encode(['source' => 'widget', 'page_url' => $conv->page_url]),
            'created_at'      => now(),
        ]);

        // The bigger half of the same bug as the $existing branch above: this
        // path (a genuinely new conversation — the common case for any
        // first-time visitor) returned the *raw, unmerged* widget_config —
        // not even the defaults every other field already had a fallback
        // for (primary_color, position, send_button_label, ...), let alone
        // welcome_message.
        $merged = $this->mergedWidgetConfig($chatbot);
        return $this->created([
            'conversation_id' => $conv->id,
            'session_id'      => $conv->session_id,
            'welcome_message' => $merged['welcome_message'],
            'language'        => $chatbot->language,
            'widget_config'   => $merged,
        ]);
    }

    public function sendMessage(Request $req): JsonResponse|StreamedResponse
    {
        // TODO(remove after legacy plugin migration): chatbot_id is nullable
        // here — not required|uuid — only because the pre-1.2.0 WordPress
        // plugin's /chat/message call never sent it, only conversation_id.
        // ValidateChatbotDomain resolves it from conversation_id in that case
        // (see the 'legacy plugin request' warning it logs) and stashes the
        // result on $request->attributes. Once that warning stops appearing
        // in the logs for a full billing cycle, every site is on >=1.2.0:
        // flip this back to 'required|uuid' and delete the fallback branch
        // in ValidateChatbotDomain.
        $d = $req->validate([
            'chatbot_id'      => 'nullable|uuid',
            'conversation_id' => 'required|uuid',
            'message'         => 'required|string|min:1|max:2000',
            'session_id'      => 'required|string|max:128',
        ]);

        $preResolved = $req->attributes->get('chatbot_index');
        $chatbotId = $d['chatbot_id'] ?? ($preResolved->chatbot_id ?? null);
        if (!$chatbotId) return $this->notFound('Chatbot not found');

        // chatbot_id (now resolved one way or another) lets us set the right
        // schema directly instead of scanning every tenant_% schema for a
        // matching conversation row — that scan was an unbounded per-message
        // query fan-out across every tenant.
        $index = $this->setSchemaFromChatbot($chatbotId, $preResolved);
        if (!$index) return $this->notFound('Chatbot not found');

        $conv = Conversation::where('id', $d['conversation_id'])
            ->where('session_id', $d['session_id'])
            ->where('chatbot_id', $chatbotId)
            ->first();
        if (!$conv) return $this->notFound('Conversation not found');

        $chatbot   = Chatbot::findOrFail($conv->chatbot_id);
        $maxMsgs   = $chatbot->widget_config['rate_limit_max_messages'] ?? null;
        $blockMins = $chatbot->widget_config['rate_limit_block_minutes'] ?? null;
        if ($maxMsgs && $blockMins) {
            $key = 'hamman-chat:' . $conv->chatbot_id . ':' . $req->ip();
            if (RateLimiter::tooManyAttempts($key, (int) $maxMsgs)) {
                $rateLimitMessage = $chatbot->widget_config['rate_limit_message']
                    ?? ($chatbot->language === 'en'
                        ? "You've reached the message limit. Please try again in a few minutes."
                        : 'تعداد پیام‌های مجاز شما به پایان رسیده. لطفاً چند دقیقه دیگر دوباره امتحان کنید.'); // i18n:widget
                return $this->tooManyRequests($rateLimitMessage, RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, (int) $blockMins * 60);
        }

        // Strict literal match, not $req->accepts('text/event-stream') —
        // that helper also matches a plain "*/*" Accept header, which is
        // what curl and older/legacy widget builds send by default. Only a
        // client that explicitly names text/event-stream gets a stream;
        // every other request — including every pre-existing WordPress
        // plugin install — gets the exact same single JSON response as
        // before this feature existed.
        if (str_contains($req->header('Accept', ''), 'text/event-stream')) {
            return $this->streamMessage($conv, $d['message']);
        }

        $r   = $this->svc->sendMessage($conv, $d['message']);
        $msg = $r['message'];

        return $this->ok([
            'message_id'  => $msg->id,
            'response'    => $msg->content,
            'model'       => $msg->model_used,
            'latency_ms'  => $msg->latency_ms,
            'is_fallback' => $msg->is_fallback,
            'sources'     => $r['result']['sources'] ?? [],
            // Product cards / comparison table (recommend_products,
            // compare_products) — see hamman-widget.js's
            // renderProductCards()/renderCompareTable(). Empty for every
            // response that didn't use one of those two tools.
            'widget_blocks' => $r['result']['widget_blocks'] ?? [],
        ]);
    }

    private function streamMessage(Conversation $conv, string $message): StreamedResponse
    {
        return response()->stream(function () use ($conv, $message) {
            $write = function (string $chunk) {
                echo $chunk;
                if (ob_get_level() > 0) { @ob_flush(); }
                flush();
            };

            try {
                $r = $this->svc->sendMessageStream($conv, $message, function (string $delta) use ($write) {
                    $write('data: ' . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n");
                });
                $msg = $r['message'];
                $write('event: done' . "\n" . 'data: ' . json_encode([
                    'message_id'    => $msg->id,
                    'model'         => $msg->model_used,
                    'latency_ms'    => $msg->latency_ms,
                    'is_fallback'   => $msg->is_fallback,
                    'sources'       => $r['result']['sources'] ?? [],
                    'widget_blocks' => $r['result']['widget_blocks'] ?? [],
                ], JSON_UNESCAPED_UNICODE) . "\n\n");
            } catch (\Throwable $e) {
                report($e);
                $write('event: error' . "\n" . 'data: ' . json_encode(['error' => 'stream_failed']) . "\n\n");
            }
        }, 200, [
            // Explicit charset — this stream carries raw UTF-8 bytes
            // (JSON_UNESCAPED_UNICODE below emits multi-byte characters
            // as-is rather than \uXXXX-escaping them), so this response
            // must not rely on a client falling back to some default
            // encoding to render Persian/Arabic text correctly.
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    public function history(Request $req, string $sessionId): JsonResponse
    {
        $chatbotId = $req->query('chatbot_id');
        if (!$chatbotId) return $this->notFound('chatbot_id required');
        $index = $this->setSchemaFromChatbot($chatbotId);
        if (!$index) return $this->notFound('Chatbot not found');
        $conv = Conversation::where('session_id', $sessionId)->first();
        if (!$conv) return $this->notFound('Session not found');
        return $this->ok([
            'conversation_id' => $conv->id,
            'messages'        => $conv->messages()->get(['id', 'role', 'content', 'created_at']),
        ]);
    }

    // Companion to history() above, keyed by conversation_id instead of
    // session_id — what the WordPress widget uses to restore a conversation
    // it persisted in localStorage across a page reload, when it no longer
    // has (or trusts) the original session_id's in-memory state.
    public function conversationMessages(Request $req, string $conversationId): JsonResponse
    {
        $chatbotId = $req->query('chatbot_id');
        if (!$chatbotId) return $this->notFound('chatbot_id required');
        $index = $this->setSchemaFromChatbot($chatbotId, $req->attributes->get('chatbot_index'));
        if (!$index) return $this->notFound('Chatbot not found');
        $conv = Conversation::where('id', $conversationId)->where('chatbot_id', $chatbotId)->first();
        if (!$conv) return $this->notFound('Conversation not found');
        return $this->ok([
            'conversation_id' => $conv->id,
            'messages'        => $conv->messages()->get(['id', 'role', 'content', 'created_at']),
        ]);
    }

    public function submitFeedback(Request $req): JsonResponse
    {
        // Previously validated its input and returned a canned success
        // response without ever persisting anything — a real 500-answers
        // silently-discarded bug, not a design choice. Needs chatbot_id
        // (this route carries no chatbot.domain middleware, unlike every
        // other /chat/* endpoint, so nothing else resolves the tenant
        // schema for it) to know which schema message_id even lives in.
        $d = $req->validate([
            'chatbot_id' => 'required|uuid',
            'message_id' => 'required|uuid',
            'rating'     => 'required|in:1,-1',
        ]);

        $index = $this->setSchemaFromChatbot($d['chatbot_id']);
        if (!$index) return $this->notFound('Chatbot not found');

        $message = DB::table('messages')->where('id', $d['message_id'])->first();
        if (!$message) return $this->notFound('Message not found');

        DB::table('conversation_events')->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $message->conversation_id,
            'message_id'      => $d['message_id'],
            'chatbot_id'      => $d['chatbot_id'],
            'event_type'      => 'feedback',
            'payload'         => json_encode(['rating' => (int) $d['rating']]),
            'created_at'      => now(),
        ]);

        return $this->ok(['message' => 'Feedback recorded']);
    }

    /**
     * The add_to_cart tool (doc-04) never adds anything itself — the real
     * WooCommerce Store API call happens in the customer's own browser,
     * same-origin with the shop, after they click a real "Add to cart"
     * button (see hamman-widget.js's handleAddToCartClick()). This is the
     * widget reporting back a REAL, CONFIRMED success so cart_add_succeeded
     * reflects an actual outcome, not merely an offer that was shown —
     * unlike cart_link_generated (build_cart_url), which logs at offer
     * time since there's no way to observe a click on an external link.
     * conversation_id is re-validated against this chatbot's own
     * conversations table before anything is logged — a client-side value
     * is never trusted blindly, same posture as SyncService::recordOrder().
     */
    public function cartEvent(Request $req): JsonResponse
    {
        $d = $req->validate([
            'chatbot_id'      => 'required|uuid',
            'conversation_id' => 'required|uuid',
            'product_id'      => 'required|integer|min:1',
            'variation_id'    => 'nullable|integer|min:1',
        ]);

        $index = $this->setSchemaFromChatbot($d['chatbot_id'], $req->attributes->get('chatbot_index'));
        if (!$index) return $this->notFound('Chatbot not found');

        $conv = Conversation::where('id', $d['conversation_id'])->where('chatbot_id', $d['chatbot_id'])->first();
        if (!$conv) return $this->notFound('Conversation not found');

        DB::table('conversation_events')->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $conv->id,
            'chatbot_id'      => $d['chatbot_id'],
            'event_type'      => 'cart_add_succeeded',
            'payload'         => json_encode([
                'product_id'   => $d['product_id'],
                'variation_id' => $d['variation_id'] ?? null,
            ]),
            'created_at'      => now(),
        ]);

        return $this->ok(['message' => 'Recorded']);
    }

    /**
     * create_payment_link (doc-04) — the ONE place a real WooCommerce
     * order is ever created from a chat conversation. Reached only after
     * a real customer click on a rendered "Confirm & Pay" button (see
     * hamman-widget.js's handleConfirmPaymentLinkClick()) — the model-
     * callable tool (product_tools.py's create_payment_link) only ever
     * returns a live PREVIEW, never creates anything itself. Every
     * security rule the task explicitly required is enforced HERE, in
     * this exact order, before anything real is created — if the task
     * says "don't build this without X", X is one of the checks below,
     * not a comment promising it'll be added later.
     */
    public function createPaymentLink(Request $req): JsonResponse
    {
        $d = $req->validate([
            'chatbot_id'           => 'required|uuid',
            'conversation_id'      => 'required|uuid',
            'items'                => 'required|array|min:1|max:10',
            'items.*.product_id'   => 'required|integer|min:1',
            'items.*.variation_id' => 'nullable|integer|min:1',
            'items.*.quantity'     => 'nullable|integer|min:1|max:20',
            'customer'             => 'nullable|array',
            'customer.name'        => 'nullable|string|max:255',
            'customer.phone'       => 'nullable|string|max:50',
            'customer.email'       => 'nullable|email|max:255',
        ]);

        $index = $this->setSchemaFromChatbot($d['chatbot_id'], $req->attributes->get('chatbot_index'));
        if (!$index) return $this->notFound('Chatbot not found');

        $conv = Conversation::where('id', $d['conversation_id'])->where('chatbot_id', $d['chatbot_id'])->first();
        if (!$conv) return $this->notFound('Conversation not found');

        $chatbot = Chatbot::find($d['chatbot_id']);
        if (!$chatbot) return $this->notFound('Chatbot not found');

        // Rule: off by default — the merchant must consciously enable it,
        // same opt-in gate every other tool in this system already uses.
        if (!in_array('create_payment_link', $chatbot->enabled_tools ?? [], true)) {
            return $this->forbidden('Payment links are not enabled for this chatbot.');
        }
        // Rule: a real cap must be explicitly configured on this chatbot —
        // never a silent platform-wide default standing in for "the
        // merchant turned this on". NULL means "not consciously set up
        // yet", full stop, regardless of what enabled_tools says.
        if ($chatbot->max_payment_link_amount === null) {
            return $this->forbidden('Payment links are not configured for this chatbot.');
        }

        // Rule: cap on draft orders per conversation (lifetime for that
        // conversation — a conversation is inherently short-lived, unlike
        // the IP cap below which is explicitly per-day).
        $convCount = DB::table('conversation_events')
            ->where('conversation_id', $d['conversation_id'])
            ->where('event_type', 'payment_link_created')
            ->count();
        if ($convCount >= self::MAX_PAYMENT_LINKS_PER_CONVERSATION) {
            return $this->tooManyRequests('Too many payment links requested in this conversation.');
        }

        // Rule: cap on draft orders per IP per day.
        $ipKey = 'hamman-payment-link:' . $d['chatbot_id'] . ':' . $req->ip();
        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_PAYMENT_LINKS_PER_IP_PER_DAY)) {
            return $this->tooManyRequests('Too many payment links requested today.', RateLimiter::availableIn($ipKey));
        }

        $tenant = app('current_tenant');
        $domain = $index->primary_domain ?? null;
        $secret = $tenant?->getWebhookSecret();
        if (!$domain || !$secret) {
            return $this->badRequest('No store connection is on file for this chatbot.');
        }

        $items = array_map(function ($i) {
            $item = ['product_id' => (int) $i['product_id'], 'quantity' => (int) ($i['quantity'] ?? 1)];
            if (!empty($i['variation_id'])) $item['variation_id'] = (int) $i['variation_id'];
            return $item;
        }, $d['items']);

        // Rule: recompute the total live, fresh, right now — never trust
        // anything the client claims about price — and reject BEFORE ever
        // creating a real order if it's over the configured cap. This is
        // a genuinely fresh check, not a formality: stock/price may have
        // changed in the time between the preview the customer saw and
        // this exact click.
        $preview = $this->paymentLinks->previewOrder($domain, $secret, $items);
        if (!$preview || !isset($preview['total'])) {
            return $this->badRequest($preview['error'] ?? 'Could not confirm this order right now. Please try again.');
        }
        if ((float) $preview['total'] > (float) $chatbot->max_payment_link_amount) {
            return $this->forbidden('This order exceeds the payment link limit for this store.');
        }

        $result = $this->paymentLinks->createDraftOrder($domain, $secret, $items, $d['customer'] ?? null);
        if (!$result || empty($result['order_id']) || empty($result['order_pay_url'])) {
            return $this->badRequest('Could not create the order right now. Please try again.');
        }

        RateLimiter::hit($ipKey, 86400);

        $total = (float) ($result['total'] ?? $preview['total']);
        $currency = $result['currency'] ?? $preview['currency'] ?? 'IRT';

        // SECURITY: order_key/order_pay_url exist only in $result, handed
        // straight back to the browser in this response below, and are
        // NEVER written to $fields, the event payload, or any log line in
        // this method — only the numeric order_id is ever persisted.
        DB::table('orders')->insert([
            'id'              => (string) Str::uuid(),
            'chatbot_id'      => $d['chatbot_id'],
            'conversation_id' => $conv->id,
            'woo_order_id'    => (int) $result['order_id'],
            'total'           => $total,
            'currency'        => $currency,
            'status'          => 'pending',
            'line_items'      => json_encode($items),
            'created_by_bot'  => true,
            'created_at'      => now(),
        ]);

        DB::table('conversation_events')->insert([
            'id'              => (string) Str::uuid(),
            'conversation_id' => $conv->id,
            'chatbot_id'      => $d['chatbot_id'],
            'event_type'      => 'payment_link_created',
            'payload'         => json_encode(['order_id' => (int) $result['order_id'], 'total' => $total, 'currency' => $currency]),
            'created_at'      => now(),
        ]);

        return $this->ok([
            'order_id'      => (int) $result['order_id'],
            'order_pay_url' => $result['order_pay_url'],
            'total'         => $total,
            'currency'      => $currency,
        ]);
    }

    /**
     * get_order_status, phase 1 (doc-04): send a verification code — but
     * only to a contact that actually appears on an order at THIS store.
     *
     * That one rule is the whole reason this endpoint can exist safely. An
     * anonymous visitor can type any phone number into a public chat
     * widget; without the order check, this endpoint would be a free
     * SMS-harassment tool billed to the merchant. So the order check runs
     * BEFORE a single message is sent or a single toman is spent, and a
     * contact with no orders here costs the merchant nothing at all.
     *
     * Deliberately NOT reachable from the model: the Python get_order_status
     * tool only returns intent for the widget to render. Sending an SMS
     * needs a real human click, exactly like add_to_cart and
     * create_payment_link before it.
     */
    public function requestOrderStatusCode(Request $req): JsonResponse
    {
        $d = $req->validate([
            'chatbot_id'      => 'required|uuid',
            'conversation_id' => 'required|uuid',
            'contact'         => 'required|string|max:255',
        ]);

        $index = $this->setSchemaFromChatbot($d['chatbot_id'], $req->attributes->get('chatbot_index'));
        if (!$index) return $this->notFound('Chatbot not found');

        $conv = Conversation::where('id', $d['conversation_id'])->where('chatbot_id', $d['chatbot_id'])->first();
        if (!$conv) return $this->notFound('Conversation not found');

        $chatbot = Chatbot::find($d['chatbot_id']);
        if (!$chatbot) return $this->notFound('Chatbot not found');
        if (!in_array('get_order_status', $chatbot->enabled_tools ?? [], true)) {
            return $this->forbidden('Order status lookup is not enabled for this chatbot.');
        }

        // Email would need a mail transport this platform does not have
        // (there is no config/mail.php and no Mailable anywhere), so a
        // non-phone contact is refused rather than silently dropped.
        $phone = OrderStatusOtp::normalizePhone($d['contact']);
        if ($phone === null) {
            return $this->badRequest('Please enter a valid mobile number.');
        }

        // Bounds how often one visitor can make this store's database do
        // an order lookup at all — counted on every attempt, including the
        // ones that never result in an SMS.
        $ipKey = 'hamman-order-status-ip:' . $req->ip();
        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_CODE_REQUESTS_PER_IP_PER_DAY)) {
            return $this->tooManyRequests('Too many requests today.', RateLimiter::availableIn($ipKey));
        }
        RateLimiter::hit($ipKey, 86400);

        // Per-contact and per-chatbot caps count REAL sends (rows only
        // exist when a message actually went out), so a flood of lookups
        // for contacts with no orders can never exhaust a real customer's
        // allowance.
        $contactSends = OrderStatusOtp::where('contact', $phone)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($contactSends >= self::MAX_CODES_PER_CONTACT_PER_HOUR) {
            return $this->tooManyRequests('Too many code requests for this number. Please try again later.');
        }

        $chatbotSends = OrderStatusOtp::where('chatbot_id', $d['chatbot_id'])
            ->where('created_at', '>=', now()->subDay())
            ->count();
        if ($chatbotSends >= self::MAX_CODES_PER_CHATBOT_PER_DAY) {
            return $this->tooManyRequests('This store has reached its daily verification limit.');
        }

        $tenant = app('current_tenant');
        $domain = $index->primary_domain ?? null;
        $secret = $tenant?->getWebhookSecret();
        if (!$domain || !$secret) {
            return $this->badRequest('No store connection is on file for this chatbot.');
        }

        // THE gate. null means the store could not be reached — which is
        // not the same as "no orders", but both mean no SMS.
        $hasOrders = $this->orderStatus->hasOrders($domain, $secret, $phone, 'phone');
        if ($hasOrders === null) {
            return $this->badRequest('Could not check orders right now. Please try again.');
        }
        if ($hasOrders === false) {
            // Deliberately a 200 with sent=false, not an error: the widget
            // shows a plain "no orders found for that number" message. No
            // code row is written and no SMS is sent, so this costs the
            // merchant nothing.
            return $this->ok(['sent' => false, 'reason' => 'no_orders']);
        }

        $cost = (int) (PlatformSetting::current()->sms_cost_toman ?? 0);
        if ($cost > 0 && (int) $tenant->wallet_balance_toman < $cost) {
            return $this->badRequest('This store cannot send a verification code right now.');
        }

        $code = (string) random_int(10000, 99999);
        if (!app(SmsService::class)->sendCode($phone, $code)) {
            // Nothing is charged and no code row is written when the
            // message never left — the customer can simply try again.
            return $this->badRequest('Could not send the code right now. Please try again.');
        }

        OrderStatusOtp::create([
            'chatbot_id'      => $d['chatbot_id'],
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conv->id,
            'contact'         => $phone,
            'contact_type'    => 'phone',
            'code_hash'       => Hash::make($code),
            'ip'              => $req->ip(),
            'expires_at'      => now()->addMinutes(self::ORDER_STATUS_CODE_TTL_MINUTES),
        ]);

        // The merchant pays for their own customers' lookups, and sees it
        // in the same wallet ledger as everything else they're billed for.
        if ($cost > 0) {
            app(WalletService::class)->applyCompletedTransaction(
                $tenant, 'sms_otp', -$cost,
                ['description' => __('wallet.sms_otp_description', ['phone' => $this->maskPhone($phone)])],
            );
        }

        return $this->ok([
            'sent'       => true,
            'contact'    => $this->maskPhone($phone),
            'expires_in' => self::ORDER_STATUS_CODE_TTL_MINUTES * 60,
        ]);
    }

    /**
     * get_order_status, phase 2 (doc-04): verify the code, then return the
     * customer's own orders — status, tracking code and item names only.
     *
     * The sanitising happens at the WordPress end (see the plugin's
     * get_orders_for_contact()), so a shipping address or a payment
     * reference never crosses the wire in the first place.
     */
    public function verifyOrderStatusCode(Request $req): JsonResponse
    {
        $d = $req->validate([
            'chatbot_id'      => 'required|uuid',
            'conversation_id' => 'required|uuid',
            'contact'         => 'required|string|max:255',
            'code'            => 'required|string|max:10',
        ]);

        $index = $this->setSchemaFromChatbot($d['chatbot_id'], $req->attributes->get('chatbot_index'));
        if (!$index) return $this->notFound('Chatbot not found');

        $conv = Conversation::where('id', $d['conversation_id'])->where('chatbot_id', $d['chatbot_id'])->first();
        if (!$conv) return $this->notFound('Conversation not found');

        $phone = OrderStatusOtp::normalizePhone($d['contact']);
        if ($phone === null) return $this->badRequest('Please enter a valid mobile number.');

        // Scoped to this chatbot: a code minted for one store can never be
        // redeemed at another, even within the same tenant.
        $otp = OrderStatusOtp::where('chatbot_id', $d['chatbot_id'])
            ->where('contact', $phone)
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if (!$otp) return $this->badRequest('Please request a code first.');
        if ($otp->expires_at->isPast()) return $this->badRequest('This code has expired. Please request a new one.');
        if ($otp->attempts >= self::MAX_ORDER_STATUS_VERIFY_ATTEMPTS) {
            return $this->tooManyRequests('Too many incorrect attempts. Please request a new code.');
        }

        if (!Hash::check($d['code'], $otp->code_hash)) {
            $otp->increment('attempts');
            return $this->badRequest('That code is not correct.');
        }

        $otp->update(['consumed_at' => now()]);

        $tenant = app('current_tenant');
        $domain = $index->primary_domain ?? null;
        $secret = $tenant?->getWebhookSecret();
        $orders = ($domain && $secret)
            ? $this->orderStatus->fetchOrders($domain, $secret, $phone, 'phone')
            : null;

        if ($orders === null) {
            return $this->badRequest('Could not load your orders right now. Please try again.');
        }

        // Only the count and the order numbers are recorded — never the
        // contact, and never the order contents.
        DB::table('conversation_events')->insert([
            'id'              => (string) Str::uuid(),
            'conversation_id' => $conv->id,
            'chatbot_id'      => $d['chatbot_id'],
            'event_type'      => 'order_status_viewed',
            'payload'         => json_encode([
                'order_count'   => count($orders),
                'order_numbers' => array_map(fn ($o) => $o['number'], $orders),
            ]),
            'created_at'      => now(),
        ]);

        return $this->ok(['orders' => $orders]);
    }

    /** 09123456789 -> 0912***6789, for anything a human or a ledger reads. */
    private function maskPhone(string $phone): string
    {
        return strlen($phone) >= 11
            ? substr($phone, 0, 4) . '***' . substr($phone, -4)
            : $phone;
    }
}