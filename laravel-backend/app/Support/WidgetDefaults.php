<?php
namespace App\Support;

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
 * what's missing. The WordPress plugin (class-hamman-public.php) applies
 * these once /chat/session responds — get_locale() only drives its own
 * *first-paint* fallback, before that response exists.
 */
class WidgetDefaults {
    public static function forLanguage(?string $language): array {
        return array_merge(self::common(), $language === 'en' ? self::english() : self::persian());
    }

    // Language-independent — not fa/en text, so both branches merge the same
    // values. primary_color/powered_by_enabled are admin-settable per chatbot
    // (see the customer portal's MyChatbots "appearance" action, which writes
    // straight into widget_config and so always overrides these defaults, the
    // same way an explicit send_button_label etc. already does).
    private static function common(): array {
        return [
            'primary_color'       => '#1B3A6B',
            'powered_by_enabled'  => true,
            'powered_by_name'     => 'HamanTech',
            'powered_by_url'      => 'https://hamantech.ir',
        ];
    }

    private static function persian(): array {
        return [
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
        ];
    }

    private static function english(): array {
        return [
            'send_button_label'      => 'Send',
            'input_placeholder'      => 'Write your message...',
            'unavailable_message'    => "The chatbot isn't available right now. Please try again later.",
            'generic_error_message'  => 'Something went wrong.',
            'connection_error_message' => 'Could not connect to the server. Please try again.',
            'quota_exceeded_response'   => 'Sorry, your account has reached its monthly usage limit. Please contact support to upgrade your plan.',
            'processing_error_response' => 'Sorry, I could not process your request.',
        ];
    }
}
