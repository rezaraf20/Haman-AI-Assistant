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

        // Content/appearance settings (welcome message, chat title, AI
        // name, quick questions, avatar, color, position) are no longer
        // editable locally at all — the customer portal (WidgetSettings
        // page) is the single source of truth for them now; see
        // ChatController::mergedWidgetConfig() / App\Support\WidgetDefaults
        // on the backend. What follows is a generic, hardcoded first-paint
        // fallback only, replaced within a fraction of a second by the real
        // values once /chat/session responds (applyWidgetConfig() in
        // hamman-widget.js) — a chatbot configured for English reads
        // English even on a Persian WP site, and vice versa, since that's
        // driven by the chatbot's own `language` column, not get_locale().
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
            // Every field below this point is a generic first-paint-only
            // fallback — see the comment above. Must not derive `position`
            // from `$dir`: the widget's on-page corner and the chatbot's
            // text direction are independent settings (hamman-widget.css's
            // :host/data-position rules).
            'position'    => 'bottom-right',
            'aiName'      => $is_fa ? 'دستیار هوشمند' : 'AI Assistant',
            'chatTitle'   => $is_fa ? 'پشتیبانی آنلاین' : 'Online Support',
            'placeholder' => $l10n_defaults['placeholder'],
            'sendButtonLabel'        => $l10n_defaults['sendButtonLabel'],
            'unavailableMessage'     => $l10n_defaults['unavailableMessage'],
            'genericErrorMessage'    => $l10n_defaults['genericErrorMessage'],
            'connectionErrorMessage' => $l10n_defaults['connectionErrorMessage'],
            'quickQuestions' => [],
            'primaryColor'     => '#1B3A6B',
            'avatarUrl'        => '',
            'poweredByEnabled' => true,
            'poweredByName'    => 'HamanTech',
            'poweredByUrl'     => 'https://hamantech.ir',
            'i18n' => $i18n,
        ];
    }
}
