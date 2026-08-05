<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Admin {
    public function add_menu_page(): void {
        add_menu_page('Hamman AI','Hamman AI','manage_options','hamman-ai-chatbot',[$this,'render'],'dashicons-format-chat',30);
    }
    public function enqueue_assets( string $hook ): void {
        if (strpos($hook,'hamman-ai-chatbot')===false) return;
        wp_enqueue_style('hamman-admin',HAMMAN_PLUGIN_URL.'admin/css/hamman-admin.css',[],HAMMAN_VERSION);
    }
    public function render(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        include HAMMAN_PLUGIN_DIR.'admin/partials/settings-page.php';
    }
    public function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('hamman_save_settings');
        update_option('hamman_api_key',       sanitize_text_field($_POST['hamman_api_key']??''));
        update_option('hamman_chatbot_id',    sanitize_text_field($_POST['hamman_chatbot_id']??''));
        update_option('hamman_webhook_secret',sanitize_text_field($_POST['hamman_webhook_secret']??''));
        update_option('hamman_api_url',       esc_url_raw($_POST['hamman_api_url']??HAMMAN_API_BASE));
        update_option('hamman_enabled',       isset($_POST['hamman_enabled'])?'1':'0');

        update_option('hamman_auto_reply_enabled', isset($_POST['hamman_auto_reply_enabled'])?'1':'0');
        update_option('hamman_ai_name',            sanitize_text_field($_POST['hamman_ai_name']??'AI BOT'));
        update_option('hamman_chat_title',         sanitize_text_field($_POST['hamman_chat_title']??''));
        update_option('hamman_welcome_text',       sanitize_textarea_field($_POST['hamman_welcome_text']??''));
        update_option('hamman_input_placeholder',  sanitize_text_field($_POST['hamman_input_placeholder']??''));
        update_option('hamman_system_instruction', sanitize_textarea_field($_POST['hamman_system_instruction']??''));
        update_option('hamman_rate_limit_max_messages',  max(1,(int)($_POST['hamman_rate_limit_max_messages']??50)));
        update_option('hamman_rate_limit_block_minutes', max(1,(int)($_POST['hamman_rate_limit_block_minutes']??15)));

        $questions = $_POST['hamman_qq_question'] ?? [];
        $answers   = $_POST['hamman_qq_answer'] ?? [];
        $qq = [];
        foreach ($questions as $i => $q) {
            $q = sanitize_text_field($q);
            $a = sanitize_text_field($answers[$i] ?? '');
            if ($q !== '' && $a !== '') $qq[] = ['question'=>$q,'answer'=>$a];
        }
        update_option('hamman_quick_questions', $qq);

        $chatbot_id = get_option('hamman_chatbot_id','');
        if (!empty($chatbot_id) && !empty(get_option('hamman_api_key',''))) {
            $client = new Hamman_Api_Client();
            $r = $client->update_widget_settings($chatbot_id, [
                'auto_reply_enabled'       => get_option('hamman_auto_reply_enabled','1') === '1',
                'ai_name'                  => get_option('hamman_ai_name','AI BOT'),
                'system_instruction'       => get_option('hamman_system_instruction',''),
                'chat_title'               => get_option('hamman_chat_title',''),
                'welcome_text'             => get_option('hamman_welcome_text',''),
                'input_placeholder'        => get_option('hamman_input_placeholder',''),
                'rate_limit_max_messages'  => (int) get_option('hamman_rate_limit_max_messages',50),
                'rate_limit_block_minutes' => (int) get_option('hamman_rate_limit_block_minutes',15),
                'quick_questions'          => $qq,
            ]);
            if (is_wp_error($r)) {
                set_transient('hamman_settings_push_error', $r->get_error_message(), 60);
                wp_redirect(admin_url('admin.php?page=hamman-ai-chatbot&saved=1&sync_warning=1'));
                exit;
            }
        }

        wp_redirect(admin_url('admin.php?page=hamman-ai-chatbot&saved=1'));
        exit;
    }
    public function manual_sync(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('hamman_manual_sync');
        $results = (new Hamman_Sync_Manager())->run_full_sync();
        set_transient('hamman_sync_results',$results,60);
        wp_redirect(admin_url('admin.php?page=hamman-ai-chatbot&synced=1'));
        exit;
    }
}
