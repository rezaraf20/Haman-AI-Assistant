<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings page: connection + sync only now — content/appearance settings
 * (welcome message, chat title, AI name, quick questions, avatar, color,
 * position, system instruction, lead-capture text) moved server-side (the
 * customer portal's WidgetSettings page is the one place to edit them now;
 * see class-hamman-public.php's build_config() docblock). This class keeps
 * only what's genuinely local to this WordPress install: the API
 * connection itself, what content types get synced, rate limiting (applies
 * per-visitor-IP on this specific site), and local housekeeping.
 */
class Hamman_Admin {
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
        update_option('hamman_api_url',       esc_url_raw($_POST['hamman_api_url']??HAMMAN_API_BASE));
        update_option('hamman_enabled',       isset($_POST['hamman_enabled'])?'1':'0');

        // همگام‌سازی / Sync scope
        update_option('hamman_sync_products', isset($_POST['hamman_sync_products'])?'1':'0');
        update_option('hamman_sync_pages',    isset($_POST['hamman_sync_pages'])?'1':'0');
        update_option('hamman_sync_pdfs',     isset($_POST['hamman_sync_pdfs'])?'1':'0');

        // نگاشت فیلدهای اصالت / Authenticity field mapping — see
        // Hamman_Product_Sync::AUTHENTICITY_FIELDS/authenticity_fields().
        // An entry is only kept when both a real type AND a non-empty key
        // were given; anything else means "not mapped", read back as null
        // at sync time — never a half-filled mapping guessed at.
        $field_mapping = [];
        foreach ( Hamman_Product_Sync::AUTHENTICITY_FIELDS as $field ) {
            $type = sanitize_key( $_POST["hamman_field_map_{$field}_type"] ?? '' );
            $key  = sanitize_text_field( $_POST["hamman_field_map_{$field}_key"] ?? '' );
            if ( in_array( $type, [ 'meta', 'attribute' ], true ) && '' !== $key ) {
                $field_mapping[ $field ] = [ 'type' => $type, 'key' => $key ];
            }
        }
        update_option( 'hamman_field_mapping', $field_mapping );

        // پیشرفته / Advanced (rate limiting stays local — it's per-visitor-IP
        // on this specific site, not a server-side/content setting)
        update_option('hamman_rate_limit_max_messages',  max(1,(int)($_POST['hamman_rate_limit_max_messages']??50)));
        update_option('hamman_rate_limit_block_minutes', max(1,(int)($_POST['hamman_rate_limit_block_minutes']??15)));
        update_option('hamman_delete_data_on_uninstall', isset($_POST['hamman_delete_data_on_uninstall'])?'1':'0');

        wp_redirect(admin_url('admin.php?page=hamman-ai-chatbot&saved=1&tab=' . ($_POST['hamman_active_tab'] ?? 'connection')));
        exit;
    }

    public function manual_sync(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('hamman_manual_sync');
        $results = (new Hamman_Sync_Manager())->run_full_sync();
        set_transient('hamman_sync_results',$results,3600);
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

    /** AJAX: "تست اتصال" — resolves and shows the actually-connected
     * chatbot's real name, not just a generic pass/fail, so a mistyped-but-
     * technically-valid-looking chatbot ID doesn't read as "success". */
    public function ajax_test_connection(): void {
        check_ajax_referer('hamman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $name = (new Hamman_Api_Client())->get_connected_chatbot_name();
        if (is_wp_error($name)) {
            self::log('Connection test failed: ' . $name->get_error_message());
            wp_send_json_error(['message' => $name->get_error_message()]);
        }
        self::log("Connection test succeeded: connected to '{$name}'");
        wp_send_json_success(['name' => $name]);
    }

    public function ajax_clear_cache(): void {
        check_ajax_referer('hamman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        delete_transient('hamman_sync_results');
        delete_transient('hamman_settings_push_error');
        delete_transient('hamman_webhook_secret_cache');
        wp_cache_flush_group('hamman');
        self::log('Cache cleared by admin');
        wp_send_json_success(['message'=>'OK']);
    }

    /** AJAX: fetches this chatbot's real webhook secret from the server
     * (public schema tenants.settings — not stored locally at all anymore,
     * so a customer can never see a stale copy). */
    public function ajax_get_webhook_secret(): void {
        check_ajax_referer('hamman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $secret = (new Hamman_Api_Client())->get_webhook_secret();
        if (is_wp_error($secret)) wp_send_json_error(['message' => $secret->get_error_message()]);
        wp_send_json_success(['secret' => $secret]);
    }

    /** AJAX: "تولید مجدد" — the old secret stops verifying immediately;
     * requires explicit confirmation client-side since it can break
     * in-flight webhook deliveries signed with the previous value. */
    public function ajax_regenerate_webhook_secret(): void {
        check_ajax_referer('hamman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $secret = (new Hamman_Api_Client())->regenerate_webhook_secret();
        if (is_wp_error($secret)) wp_send_json_error(['message' => $secret->get_error_message()]);
        // Otherwise the next outgoing product/page webhook would still sign
        // with the now-invalid old secret for up to an hour — see
        // send_webhook()'s transient cache.
        delete_transient('hamman_webhook_secret_cache');
        self::log('Webhook secret regenerated');
        wp_send_json_success(['secret' => $secret]);
    }

    /** AJAX: fetches the current content/appearance settings from the
     * server for the read-only display in the "ظاهر و متن‌ها" tab. */
    public function ajax_get_widget_settings(): void {
        check_ajax_referer('hamman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $chatbot_id = get_option('hamman_chatbot_id','');
        if (empty($chatbot_id)) {
            wp_send_json_error(['message' => 'ابتدا شناسه‌ی چت‌بات را در تب اتصال تنظیم کنید / Set the Chatbot ID in the Connection tab first']);
        }
        $client = new Hamman_Api_Client();
        $r = $client->get_widget_settings_for_display($chatbot_id);
        if (is_wp_error($r)) wp_send_json_error(['message' => $r->get_error_message()]);
        wp_send_json_success($r);
    }

    /** AJAX: one-time push of this site's local content settings to the
     * server — only relevant for a site that was on an older plugin
     * version where these fields were still stored/edited locally.
     * Requires the admin's explicit confirmation client-side since it can
     * overwrite whatever's currently configured in the customer portal. */
    public function ajax_migrate_to_server(): void {
        check_ajax_referer('hamman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $chatbot_id = get_option('hamman_chatbot_id','');
        if (empty($chatbot_id)) {
            wp_send_json_error(['message' => 'ابتدا شناسه‌ی چت‌بات را در تب اتصال تنظیم کنید / Set the Chatbot ID in the Connection tab first']);
        }

        $payload = array_filter([
            'ai_name'            => get_option('hamman_ai_name', null),
            'chat_title'         => get_option('hamman_chat_title', null),
            'welcome_text'       => get_option('hamman_welcome_text', null),
            'input_placeholder'  => get_option('hamman_input_placeholder', null),
            'system_instruction' => get_option('hamman_system_instruction', null),
            'primary_color'      => get_option('hamman_primary_color', null),
            'position'           => get_option('hamman_widget_position', null),
            'avatar_url'         => get_option('hamman_avatar_url', null),
        ], fn ($v) => $v !== null && $v !== '');
        $qq = get_option('hamman_quick_questions', []);
        if (is_array($qq) && !empty($qq)) $payload['quick_questions'] = $qq;

        if (empty($payload)) {
            wp_send_json_error(['message' => 'هیچ تنظیمات محلی‌ای برای انتقال پیدا نشد / No local settings found to migrate']);
        }

        $r = (new Hamman_Api_Client())->update_widget_settings($chatbot_id, $payload);
        if (is_wp_error($r)) {
            self::log('Migrate-to-server failed: ' . $r->get_error_message());
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        self::log('Local content settings migrated to server');
        wp_send_json_success(['message' => 'OK']);
    }

    /** AJAX: compares HAMMAN_VERSION against the server's advertised
     * latest version (see config('hamman.wp_plugin.latest_version') on
     * the Laravel side) — public endpoint, no API key required. */
    public function ajax_check_version(): void {
        check_ajax_referer('hamman_admin_ajax','nonce');
        $api_url = rtrim(get_option('hamman_api_url', HAMMAN_API_BASE), '/');
        $response = wp_remote_get($api_url . '/wp-plugin/latest-version', ['timeout' => 10]);
        if (is_wp_error($response)) wp_send_json_error(['message' => $response->get_error_message()]);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $latest = $body['latest_version'] ?? null;
        wp_send_json_success([
            'current'         => HAMMAN_VERSION,
            'latest'          => $latest,
            'update_available' => $latest && version_compare($latest, HAMMAN_VERSION, '>'),
        ]);
    }
}
