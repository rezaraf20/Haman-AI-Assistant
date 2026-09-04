<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Public {
    public function enqueue_assets(): void {
        if (get_option('hamman_enabled','1') !== '1') return;
        $chatbot_id = get_option('hamman_chatbot_id','');
        if (empty($chatbot_id)) return;

        wp_enqueue_style(
            'hamman-widget',
            HAMMAN_PLUGIN_URL . 'public/css/hamman-widget.css',
            [],
            HAMMAN_VERSION
        );
        wp_enqueue_script(
            'hamman-widget',
            HAMMAN_PLUGIN_URL . 'public/js/hamman-widget.js',
            [],
            HAMMAN_VERSION,
            true
        );

        // The script also loads this same stylesheet a second time, inside
        // its own Shadow DOM root — see hamman-widget.js. That's what
        // actually isolates the widget's CSS from the host theme; this
        // wp_enqueue_style() call is what makes it a real, versioned,
        // cacheable WordPress asset in the first place.
        wp_localize_script('hamman-widget', 'HammanWidgetConfig', $this->build_config($chatbot_id));
    }

    private function build_config(string $chatbot_id): array {
        $api_url = rtrim(get_option('hamman_api_url', HAMMAN_API_BASE), '/');

        $qq = get_option('hamman_quick_questions', []);
        if (!is_array($qq)) $qq = [];

        // First-paint-only fallback, before /chat/session has responded with
        // this chatbot's own widget_config (see ChatController::
        // mergedWidgetConfig() and App\Support\WidgetDefaults on the backend)
        // — that response, once it lands, always wins: a chatbot configured
        // for English by its owner reads English even on a Persian WP site,
        // and vice versa (see applyWidgetConfig() in hamman-widget.js). This
        // is only what a visitor sees for the fraction of a second before
        // that happens.
        $is_fa = strpos(get_locale(), 'fa') === 0;
        $dir   = $is_fa ? 'rtl' : 'ltr';
        $l10n_defaults = $is_fa ? [
            'sendButtonLabel'        => 'ارسال',
            'placeholder'            => 'پیام خود را بنویسید...',
            'unavailableMessage'     => 'چت‌بات در حال حاضر در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.',
            'genericErrorMessage'    => 'خطایی رخ داد.',
            'connectionErrorMessage' => 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.',
        ] : [
            'sendButtonLabel'        => 'Send',
            'placeholder'            => 'Write your message...',
            'unavailableMessage'     => "The chatbot isn't available right now. Please try again later.",
            'genericErrorMessage'    => 'Something went wrong.',
            'connectionErrorMessage' => 'Could not connect to the server. Please try again.',
        ];
        $i18n = $is_fa ? [
            'dialogLabel'          => 'گفتگو با پشتیبانی هوشمند',
            'closeLabel'           => 'بستن گفتگو',
            'openLabel'            => 'باز کردن گفتگو',
            'copyLabel'            => 'کپی پیام',
            'copiedLabel'          => 'کپی شد',
            'scrollToBottomLabel'  => 'رفتن به پایین',
        ] : [
            'dialogLabel'          => 'Chat with AI assistant',
            'closeLabel'           => 'Close chat',
            'openLabel'            => 'Open chat',
            'copyLabel'            => 'Copy message',
            'copiedLabel'          => 'Copied',
            'scrollToBottomLabel'  => 'Scroll to bottom',
        ];

        return [
            'chatbotId'   => $chatbot_id,
            'apiUrl'      => $api_url,
            'cssUrl'      => HAMMAN_PLUGIN_URL . 'public/css/hamman-widget.css',
            'dir'         => $dir,
            'aiName'      => get_option('hamman_ai_name','AI BOT'),
            'chatTitle'   => get_option('hamman_chat_title','') ?: get_option('hamman_ai_name','AI BOT'),
            'placeholder' => get_option('hamman_input_placeholder','') ?: $l10n_defaults['placeholder'],
            'sendButtonLabel'        => $l10n_defaults['sendButtonLabel'],
            'unavailableMessage'     => $l10n_defaults['unavailableMessage'],
            'genericErrorMessage'    => $l10n_defaults['genericErrorMessage'],
            'connectionErrorMessage' => $l10n_defaults['connectionErrorMessage'],
            'quickQuestions' => $qq,
            // Overridden as soon as /chat/session responds with this
            // chatbot's real widget_config (App\Support\WidgetDefaults on the
            // backend) — not hardcoded, so a customer's color-picker choice
            // and, later, a white-label plan's branding toggle both actually
            // take effect. These are only the pre-response fallback.
            'primaryColor'     => '#1B3A6B',
            'poweredByEnabled' => true,
            'poweredByName'    => 'HamanTech',
            'poweredByUrl'     => 'https://hamantech.ir',
            'i18n' => $i18n,
        ];
    }
}
