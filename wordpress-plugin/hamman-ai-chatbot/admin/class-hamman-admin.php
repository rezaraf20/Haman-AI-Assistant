<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Admin {
    // Capped rolling log for the "پیشرفته" (Advanced) tab — not a real
    // logging library, just enough for a site admin to see recent sync/
    // connection failures without SSH access. Newest first.
    const LOG_OPTION = 'hamman_debug_log';
    const LOG_MAX_ENTRIES = 100;

    public function add_menu_page(): void {
        add_menu_page('Hamman AI','Hamman AI','manage_options','hamman-ai-chatbot',[$this,'render'],'dashicons-format-chat',30);
    }
    public function enqueue_assets( string $hook ): void {
        if (strpos($hook,'hamman-ai-chatbot')===false) return;
        wp_enqueue_style('hamman-admin',HAMMAN_PLUGIN_URL.'admin/css/hamman-admin.css',[],HAMMAN_VERSION);
        wp_enqueue_script('hamman-admin',HAMMAN_PLUGIN_URL.'admin/js/hamman-admin.js',[],HAMMAN_VERSION,true);
        wp_localize_script('hamman-admin','HammanAdmin',[
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hamman_admin_ajax'),
        ]);
    }
    public function render(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        include HAMMAN_PLUGIN_DIR.'admin/partials/settings-page.php';
    }

    public static function log( string $message ): void {
        $log = get_option( self::LOG_OPTION, [] );
        if ( ! is_array( $log ) ) $log = [];
        array_unshift( $log, [ 'time' => time(), 'message' => $message ] );
        $log = array_slice( $log, 0, self::LOG_MAX_ENTRIES );
        update_option( self::LOG_OPTION, $log );
    }

    public function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('hamman_save_settings');

        // اتصال / Connection
        update_option('hamman_api_key',       sanitize_text_field($_POST['hamman_api_key']??''));
        update_option('hamman_chatbot_id',    sanitize_text_field($_POST['hamman_chatbot_id']??''));
        update_option('hamman_webhook_secret',sanitize_text_field($_POST['hamman_webhook_secret']??''));
        update_option('hamman_api_url',       esc_url_raw($_POST['hamman_api_url']??HAMMAN_API_BASE));
        update_option('hamman_enabled',       isset($_POST['hamman_enabled'])?'1':'0');

        // ظاهر / Appearance
        update_option('hamman_primary_color',    sanitize_hex_color($_POST['hamman_primary_color']??'') ?: '#1B3A6B');
        update_option('hamman_widget_position',  in_array($_POST['hamman_widget_position']??'', ['bottom-left','bottom-right'], true) ? $_POST['hamman_widget_position'] : 'bottom-right');
        update_option('hamman_avatar_url',       esc_url_raw($_POST['hamman_avatar_url']??''));

        // متن‌ها / Texts
        update_option('hamman_auto_reply_enabled', isset($_POST['hamman_auto_reply_enabled'])?'1':'0');
        update_option('hamman_ai_name',            sanitize_text_field($_POST['hamman_ai_name']??'AI BOT'));
        update_option('hamman_chat_title',         sanitize_text_field($_POST['hamman_chat_title']??''));
        update_option('hamman_welcome_text',       sanitize_textarea_field($_POST['hamman_welcome_text']??''));
        update_option('hamman_input_placeholder',  sanitize_text_field($_POST['hamman_input_placeholder']??''));
        update_option('hamman_system_instruction', sanitize_textarea_field($_POST['hamman_system_instruction']??''));
        update_option('hamman_fallback_response',  sanitize_textarea_field($_POST['hamman_fallback_response']??''));
        update_option('hamman_rate_limit_max_messages',  max(1,(int)($_POST['hamman_rate_limit_max_messages']??50)));
        update_option('hamman_rate_limit_block_minutes', max(1,(int)($_POST['hamman_rate_limit_block_minutes']??15)));
        update_option('hamman_lead_capture_enabled', isset($_POST['hamman_lead_capture_enabled'])?'1':'0');
        update_option('hamman_lead_capture_prompt',  sanitize_textarea_field($_POST['hamman_lead_capture_prompt']??''));
        update_option('hamman_lead_capture_thanks',  sanitize_textarea_field($_POST['hamman_lead_capture_thanks']??''));
        update_option('hamman_lead_capture_invalid', sanitize_textarea_field($_POST['hamman_lead_capture_invalid']??''));

        $questions = $_POST['hamman_qq_question'] ?? [];
        $answers   = $_POST['hamman_qq_answer'] ?? [];
        $qq = [];
        foreach ($questions as $i => $q) {
            $q = sanitize_text_field($q);
            $a = sanitize_text_field($answers[$i] ?? '');
            if ($q !== '' && $a !== '') $qq[] = ['question'=>$q,'answer'=>$a];
        }
        update_option('hamman_quick_questions', $qq);

        // همگام‌سازی / Sync scope
        update_option('hamman_sync_products', isset($_POST['hamman_sync_products'])?'1':'0');
        update_option('hamman_sync_pages',    isset($_POST['hamman_sync_pages'])?'1':'0');
        update_option('hamman_sync_pdfs',     isset($_POST['hamman_sync_pdfs'])?'1':'0');

        // پیشرفته / Advanced
        update_option('hamman_delete_data_on_uninstall', isset($_POST['hamman_delete_data_on_uninstall'])?'1':'0');

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
                'fallback_response'        => get_option('hamman_fallback_response',''),
                'rate_limit_max_messages'  => (int) get_option('hamman_rate_limit_max_messages',50),
                'rate_limit_block_minutes' => (int) get_option('hamman_rate_limit_block_minutes',15),
                'quick_questions'          => $qq,
                'primary_color'            => get_option('hamman_primary_color','#1B3A6B'),
                'position'                 => get_option('hamman_widget_position','bottom-right'),
                'avatar_url'               => get_option('hamman_avatar_url',''),
                'lead_capture_enabled'     => get_option('hamman_lead_capture_enabled','0') === '1',
                'lead_capture_prompt'      => get_option('hamman_lead_capture_prompt',''),
                'lead_capture_thanks'      => get_option('hamman_lead_capture_thanks',''),
                'lead_capture_invalid'     => get_option('hamman_lead_capture_invalid',''),
            ]);
            if (is_wp_error($r)) {
                self::log('Settings push to Hamman failed: ' . $r->get_error_message());
                set_transient('hamman_settings_push_error', $r->get_error_message(), 60);
                wp_redirect(admin_url('admin.php?page=hamman-ai-chatbot&saved=1&sync_warning=1&tab=' . ($_POST['hamman_active_tab'] ?? 'connection')));
                exit;
            }
        }

        wp_redirect(admin_url('admin.php?page=hamman-ai-chatbot&saved=1&tab=' . ($_POST['hamman_active_tab'] ?? 'connection')));
        exit;
    }

    public function manual_sync(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('hamman_manual_sync');
        $results = (new Hamman_Sync_Manager())->run_full_sync();
        set_transient('hamman_sync_results',$results,60);
        if (!empty($results['error'])) {
            self::log('Manual sync failed: ' . $results['error']);
        } else {
            foreach ($results as $type => $r) {
                if (!empty($r['errors'])) self::log("Sync ({$type}) completed with errors: " . wp_json_encode($r['errors']));
            }
            self::log('Manual sync completed: ' . wp_json_encode($results));
        }
        wp_redirect(admin_url('admin.php?page=hamman-ai-chatbot&synced=1&tab=sync'));
        exit;
    }

    /** AJAX: "تست اتصال" button in the Connection tab — uses whatever key/
     * URL is currently saved (the form must be saved first if the admin
     * just typed a new key), calling the same verify_connection() the rest
     * of the plugin relies on, so a green check here means sync will
     * actually work, not just that the request format is accepted. */
    public function ajax_test_connection(): void {
        check_ajax_referer('hamman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'No permission'], 403);

        $client = new Hamman_Api_Client();
        $ok = $client->verify_connection();
        if ($ok) {
            self::log('Connection test: success');
            wp_send_json_success(['message'=>'OK']);
        } else {
            self::log('Connection test: failed');
            wp_send_json_error(['message'=>'Failed']);
        }
    }

    /** AJAX: "پاک کردن کش" — clears the transients this plugin itself sets
     * (sync results/errors) plus any object-cache entries under its group.
     * There's no dedicated persistent query/response cache in this plugin
     * beyond these transients, so this is the complete, honest scope of
     * "clear cache" for it today. */
    public function ajax_clear_cache(): void {
        check_ajax_referer('hamman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'No permission'], 403);

        delete_transient('hamman_sync_results');
        delete_transient('hamman_settings_push_error');
        wp_cache_flush_group('hamman');
        self::log('Cache cleared by admin');
        wp_send_json_success(['message'=>'OK']);
    }
}
