<?php
namespace App\Support;

use App\Models\Tenant\Chatbot;

/**
 * Default chat-widget UI text (send button, placeholder, error/unavailable
 * messages), keyed by the owning chatbot's own `language` column — not the
 * admin/customer panel's locale system in lang/. A chatbot embedded on an
 * English WooCommerce store should read English even if the store owner runs
 * their own admin panel in Persian, and vice versa.
 *
 * ChatController::createSession() merges this under whatever's already in
 * the chatbot's widget_config, so an admin-set value (via
 * ChatbotController::updateWidgetSettings) always wins; this only fills in
 * what's missing. The WordPress plugin (class-haman-public.php) applies
 * these once /chat/session responds — get_locale() only drives its own
 * *first-paint* fallback, before that response exists.
 */
class WidgetDefaults {
    public static function forLanguage(?string $language): array {
        return array_merge(self::common(), $language === 'en' ? self::english() : self::persian());
    }

    /**
     * The single place that produces a chatbot's complete, always-defaulted
     * widget config — used by both ChatController (what the widget actually
     * receives) and ChatbotController (what the WordPress plugin's
     * read-only display and the customer portal's WidgetSettings page read
     * back). welcome_message/system_instruction also have their own
     * top-level chatbots.welcome_message/system_prompt columns — the real,
     * RAG-pipeline-facing source of truth for the latter (see ChatService::
     * gatewayPayload()) — which always win here over both the generic
     * default and anything stale left in widget_config for the same key.
     */
    public static function merge(Chatbot $chatbot): array {
        $merged = array_merge(self::forLanguage($chatbot->language), $chatbot->widget_config ?? []);
        if (filled($chatbot->welcome_message)) $merged['welcome_message'] = $chatbot->welcome_message;
        if (filled($chatbot->system_prompt)) $merged['system_instruction'] = $chatbot->system_prompt;
        return $merged;
    }

    // Language-independent — not fa/en text, so both branches merge the same
    // values. primary_color/powered_by_enabled are admin-settable per chatbot
    // (see the customer portal's MyChatbots "appearance" action, which writes
    // straight into widget_config and so always overrides these defaults, the
    // same way an explicit send_button_label etc. already does).
    private static function common(): array {
        return [
            'primary_color'       => config('haman.brand.primary_color'),
            'powered_by_enabled'  => true,
            'powered_by_name'     => config('haman.brand.name'),
            'powered_by_url'      => config('haman.brand.url'),
            // Which corner of the page the floating widget sits in — must
            // NOT be derived from the chatbot's text direction (a Persian
            // site owner may still want the widget bottom-right, matching
            // most competitors' convention); admin/customer-settable, same
            // override path as primary_color above.
            'position'            => 'bottom-right',
            // No sensible generic default beyond "nothing extra/none" — a
            // made-up quick question or avatar image would be worse than
            // none at all. system_instruction here is only ever surfaced
            // for display (the customer portal reading back what's
            // configured); the actual grounding rules are always applied
            // server-side by rag_service.py regardless of this value.
            'quick_questions'     => [],
            'system_instruction'  => '',
            'avatar_url'          => '',
        ];
    }

    private static function persian(): array {
        return [
            // The actual root cause of a real reported bug: a chatbot
            // created (or never re-saved) without an explicit welcome
            // message had NO fallback at all — createSession() read the
            // raw chatbots.welcome_message column directly, bypassing this
            // whole default-merge pattern that every other widget string
            // already goes through. See ChatController::createSession().
            'welcome_message'        => 'سلام! چطور می‌توانم کمکتان کنم؟',
            'chat_title'             => 'پشتیبانی آنلاین',
            'ai_name'                => 'دستیار هوشمند',
            'send_button_label'      => 'ارسال',
            'input_placeholder'      => 'پیام خود را بنویسید...',
            'unavailable_message'    => 'چت‌بات در حال حاضر در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.',
            'generic_error_message'  => 'خطایی رخ داد.',
            'connection_error_message' => 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.',
            // Used server-side by ChatService as the last-resort bot
            // "response" text (only when the chatbot has no fallback_response
            // of its own) — distinct from the client-side widget strings
            // above, which are fetch/UI errors the JS itself shows.
            'quota_exceeded_response'   => 'با عرض پوزش، سقف مصرف ماهانه‌ی حساب شما تمام شده است. لطفاً برای ارتقای پلن با پشتیبانی تماس بگیرید.',
            'processing_error_response' => 'با عرض پوزش، امکان پردازش درخواست شما وجود نداشت.',
            // See LeadCaptureService — only shown when the chatbot's
            // widget_config.lead_capture_enabled is true; otherwise the
            // plain fallback_response above still applies unchanged.
            'lead_capture_prompt'  => 'الان جوابش را ندارم. شماره تماس یا ایمیلتان را بگذارید تا همکاران در اولین فرصت باهاتون تماس بگیرند.',
            'lead_capture_thanks'  => 'متشکریم! اطلاعات شما ثبت شد و همکاران به‌زودی با شما تماس می‌گیرند.',
            'lead_capture_invalid' => 'این یک شماره تماس یا ایمیل معتبر به نظر نمی‌رسد. لطفاً دوباره وارد کنید (مثال: ۰۹۱۲۳۴۵۶۷۸۹ یا you@example.com).',
            // :item is replaced with what the customer actually asked for.
            // Both modes are off unless the merchant turns them on.
            'lead_capture_out_of_stock_enabled'   => false,
            'lead_capture_not_in_catalog_enabled' => false,
            'lead_capture_out_of_stock_prompt' => 'اگر شماره‌تان را بگذارید، به‌محض شارژ شدن «:item» خبرتان می‌کنم.',
            'lead_capture_out_of_stock_thanks' => 'ثبت شد! به‌محض موجود شدن، خبرتان می‌کنیم.',
            'lead_capture_not_in_catalog_prompt' => 'فعلاً «:item» را نداریم. اگر شماره‌تان را بگذارید، در صورت تأمین به شما اطلاع می‌دهیم.',
            'lead_capture_not_in_catalog_thanks' => 'ثبت شد! اگر این مورد را تأمین کنیم، به شما خبر می‌دهیم.',
        ];
    }

    private static function english(): array {
        return [
            'welcome_message'        => 'Hi! How can I help you?',
            'chat_title'             => 'Online Support',
            'ai_name'                => 'AI Assistant',
            'send_button_label'      => 'Send',
            'input_placeholder'      => 'Write your message...',
            'unavailable_message'    => "The chatbot isn't available right now. Please try again later.",
            'generic_error_message'  => 'Something went wrong.',
            'connection_error_message' => 'Could not connect to the server. Please try again.',
            'quota_exceeded_response'   => 'Sorry, your account has reached its monthly usage limit. Please contact support to upgrade your plan.',
            'processing_error_response' => 'Sorry, I could not process your request.',
            'lead_capture_prompt'  => "I don't have an answer for that right now. Leave your phone number or email and our team will get back to you shortly.",
            'lead_capture_thanks'  => "Thanks! We've got your details and our team will be in touch soon.",
            'lead_capture_invalid' => "That doesn't look like a valid phone number or email. Please try again (e.g. +1 555 123 4567 or you@example.com).",
            'lead_capture_out_of_stock_enabled'   => false,
            'lead_capture_not_in_catalog_enabled' => false,
            'lead_capture_out_of_stock_prompt' => 'Leave your phone number and I\'ll let you know as soon as ":item" is back in stock.',
            'lead_capture_out_of_stock_thanks' => "Got it! We'll let you know the moment it's back in stock.",
            'lead_capture_not_in_catalog_prompt' => 'We don\'t carry ":item" at the moment. Leave your number and we\'ll let you know if we start stocking it.',
            'lead_capture_not_in_catalog_thanks' => "Got it! We'll be in touch if we start stocking that.",
        ];
    }
}
