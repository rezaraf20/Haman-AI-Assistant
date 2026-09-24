<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings page: connection + sync only now — content/appearance settings
 * (welcome message, chat title, AI name, quick questions, avatar, color,
 * position, system instruction, lead-capture text) moved server-side (the
 * customer portal's WidgetSettings page is the one place to edit them now;
 * see class-haman-public.php's build_config() docblock). This class keeps
 * only what's genuinely local to this WordPress install: the API
 * connection itself, what content types get synced, rate limiting (applies
 * per-visitor-IP on this specific site), and local housekeeping.
 */
class Haman_Admin {
    const LOG_OPTION = 'haman_debug_log';
    const LOG_MAX_ENTRIES = 100;

    public function add_menu_page(): void {
        add_menu_page('Haman AI','Haman AI','manage_options','haman-ai-chatbot',[$this,'render'],'dashicons-format-chat',30);
    }
    public function enqueue_assets( string $hook ): void {
        if (strpos($hook,'haman-ai-chatbot')===false) return;
        wp_enqueue_style('haman-admin',HAMAN_PLUGIN_URL.'admin/css/haman-admin.css',[],HAMAN_VERSION);
        wp_enqueue_script('haman-admin',HAMAN_PLUGIN_URL.'admin/js/haman-admin.js',[],HAMAN_VERSION,true);
        wp_localize_script('haman-admin','HamanAdmin',[
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('haman_admin_ajax'),
        ]);
    }
    public function render(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        include HAMAN_PLUGIN_DIR.'admin/partials/settings-page.php';
    }

    /** Keeps only comma-separated positive integers — what
     * Haman_Page_Sync::excluded_ids() (WordPress side) and
     * App\Support\SyncSettings (server side) both expect to parse. */
    private function sanitize_id_list( string $raw ): string {
        $ids = $this->id_list_to_array($raw);
        return implode(', ', $ids);
    }
    private function id_list_to_array( string $raw ): array {
        if ('' === trim($raw)) return [];
        return array_values(array_filter(array_map('intval', array_map('trim', explode(',', $raw)))));
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
        check_admin_referer('haman_save_settings');

        // اتصال / Connection
        update_option('haman_api_key',       sanitize_text_field($_POST['haman_api_key']??''));
        update_option('haman_chatbot_id',    sanitize_text_field($_POST['haman_chatbot_id']??''));
        update_option('haman_api_url',       esc_url_raw($_POST['haman_api_url']??HAMAN_API_BASE));
        update_option('haman_enabled',       isset($_POST['haman_enabled'])?'1':'0');

        // همگام‌سازی / Sync scope. Pages and posts are two independent
        // toggles (see Haman_Page_Sync::enabled_post_types()'s docblock for
        // the incident behind splitting them) — posts default OFF, unlike
        // every other checkbox here, since an unchecked box and "not sent
        // in $_POST at all" are indistinguishable and a fresh install must
        // not silently start syncing blog posts.
        update_option('haman_sync_products', isset($_POST['haman_sync_products'])?'1':'0');
        update_option('haman_sync_pages',    isset($_POST['haman_sync_pages'])?'1':'0');
        update_option('haman_sync_posts',    isset($_POST['haman_sync_posts'])?'1':'0');
        update_option('haman_sync_pdfs',     isset($_POST['haman_sync_pdfs'])?'1':'0');
        update_option('haman_sync_excluded_category_ids', $this->sanitize_id_list($_POST['haman_sync_excluded_category_ids']??''));
        update_option('haman_sync_excluded_page_ids',     $this->sanitize_id_list($_POST['haman_sync_excluded_page_ids']??''));

        // Best-effort push to the server, so a change made here also shows
        // up in the customer portal's SyncSettings page without the admin
        // having to open that separately — mirrors ajax_migrate_to_server()'s
        // reasoning, just synchronous since this is a handful of scalar
        // fields on an already-full-page POST, not worth a second AJAX round
        // trip. Never blocks the redirect below: a merchant without a
        // working API connection yet must still be able to save local
        // settings, exactly like every other field on this page already can.
        $chatbot_id = get_option('haman_chatbot_id', '');
        if (!empty($chatbot_id)) {
            $r = (new Haman_Api_Client())->update_sync_settings($chatbot_id, [
                'sync_products'         => get_option('haman_sync_products') === '1',
                'sync_pages'            => get_option('haman_sync_pages') === '1',
                'sync_posts'            => get_option('haman_sync_posts') === '1',
                'excluded_category_ids' => $this->id_list_to_array(get_option('haman_sync_excluded_category_ids', '')),
                'excluded_page_ids'     => $this->id_list_to_array(get_option('haman_sync_excluded_page_ids', '')),
            ]);
            if (is_wp_error($r)) self::log('Push sync settings to server failed: ' . $r->get_error_message());
        }

        // نگاشت فیلدهای اصالت / Authenticity field mapping — see
        // Haman_Product_Sync::AUTHENTICITY_FIELDS/authenticity_fields().
        // An entry is only kept when both a real type AND a non-empty key
        // were given; anything else means "not mapped", read back as null
        // at sync time — never a half-filled mapping guessed at.
        $field_mapping = [];
        foreach ( Haman_Product_Sync::AUTHENTICITY_FIELDS as $field ) {
            $type = sanitize_key( $_POST["haman_field_map_{$field}_type"] ?? '' );
            $key  = sanitize_text_field( $_POST["haman_field_map_{$field}_key"] ?? '' );
            if ( in_array( $type, [ 'meta', 'attribute' ], true ) && '' !== $key ) {
                $field_mapping[ $field ] = [ 'type' => $type, 'key' => $key ];
            }
        }
        update_option( 'haman_field_mapping', $field_mapping );

        // پیشرفته / Advanced (rate limiting stays local — it's per-visitor-IP
        // on this specific site, not a server-side/content setting)
        update_option('haman_rate_limit_max_messages',  max(1,(int)($_POST['haman_rate_limit_max_messages']??50)));
        update_option('haman_rate_limit_block_minutes', max(1,(int)($_POST['haman_rate_limit_block_minutes']??15)));
        update_option('haman_delete_data_on_uninstall', isset($_POST['haman_delete_data_on_uninstall'])?'1':'0');

        wp_redirect(admin_url('admin.php?page=haman-ai-chatbot&saved=1&tab=' . ($_POST['haman_active_tab'] ?? 'connection')));
        exit;
    }

    public function manual_sync(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
        check_admin_referer('haman_manual_sync');
        $results = (new Haman_Sync_Manager())->run_full_sync();
        set_transient('haman_sync_results',$results,3600);
        if (!empty($results['error'])) {
            self::log('Manual sync failed: ' . $results['error']);
        } else {
            foreach ($results as $type => $r) {
                if (!empty($r['errors'])) self::log("Sync ({$type}) completed with errors: " . wp_json_encode($r['errors']));
            }
            self::log('Manual sync completed: ' . wp_json_encode($results));
        }
        wp_redirect(admin_url('admin.php?page=haman-ai-chatbot&synced=1&tab=sync'));
        exit;
    }

    /** AJAX: "تست اتصال" — resolves and shows the actually-connected
     * chatbot's real name, not just a generic pass/fail, so a mistyped-but-
     * technically-valid-looking chatbot ID doesn't read as "success". */
    public function ajax_test_connection(): void {
        check_ajax_referer('haman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $name = (new Haman_Api_Client())->get_connected_chatbot_name();
        if (is_wp_error($name)) {
            self::log('Connection test failed: ' . $name->get_error_message());
            wp_send_json_error(['message' => $name->get_error_message()]);
        }
        self::log("Connection test succeeded: connected to '{$name}'");
        wp_send_json_success(['name' => $name]);
    }

    public function ajax_clear_cache(): void {
        check_ajax_referer('haman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        delete_transient('haman_sync_results');
        delete_transient('haman_settings_push_error');
        delete_transient('haman_webhook_secret_cache');
        wp_cache_flush_group('haman');
        self::log('Cache cleared by admin');
        wp_send_json_success(['message'=>'OK']);
    }

    /** AJAX: fetches this chatbot's real webhook secret from the server
     * (public schema tenants.settings — not stored locally at all anymore,
     * so a customer can never see a stale copy). */
    public function ajax_get_webhook_secret(): void {
        check_ajax_referer('haman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $secret = (new Haman_Api_Client())->get_webhook_secret();
        if (is_wp_error($secret)) wp_send_json_error(['message' => $secret->get_error_message()]);
        wp_send_json_success(['secret' => $secret]);
    }

    /** AJAX: "تولید مجدد" — the old secret stops verifying immediately;
     * requires explicit confirmation client-side since it can break
     * in-flight webhook deliveries signed with the previous value. */
    public function ajax_regenerate_webhook_secret(): void {
        check_ajax_referer('haman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $secret = (new Haman_Api_Client())->regenerate_webhook_secret();
        if (is_wp_error($secret)) wp_send_json_error(['message' => $secret->get_error_message()]);
        // Otherwise the next outgoing product/page webhook would still sign
        // with the now-invalid old secret for up to an hour — see
        // send_webhook()'s transient cache.
        delete_transient('haman_webhook_secret_cache');
        self::log('Webhook secret regenerated');
        wp_send_json_success(['secret' => $secret]);
    }

    /** AJAX: fetches the current content/appearance settings from the
     * server for the read-only display in the "ظاهر و متن‌ها" tab. */
    public function ajax_get_widget_settings(): void {
        check_ajax_referer('haman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $chatbot_id = get_option('haman_chatbot_id','');
        if (empty($chatbot_id)) {
            wp_send_json_error(['message' => 'ابتدا شناسه‌ی چت‌بات را در تب اتصال تنظیم کنید / Set the Chatbot ID in the Connection tab first']);
        }
        $client = new Haman_Api_Client();
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
        check_ajax_referer('haman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $chatbot_id = get_option('haman_chatbot_id','');
        if (empty($chatbot_id)) {
            wp_send_json_error(['message' => 'ابتدا شناسه‌ی چت‌بات را در تب اتصال تنظیم کنید / Set the Chatbot ID in the Connection tab first']);
        }

        $payload = array_filter([
            'ai_name'            => get_option('haman_ai_name', null),
            'chat_title'         => get_option('haman_chat_title', null),
            'welcome_text'       => get_option('haman_welcome_text', null),
            'input_placeholder'  => get_option('haman_input_placeholder', null),
            'system_instruction' => get_option('haman_system_instruction', null),
            'primary_color'      => get_option('haman_primary_color', null),
            'position'           => get_option('haman_widget_position', null),
            'avatar_url'         => get_option('haman_avatar_url', null),
        ], fn ($v) => $v !== null && $v !== '');
        $qq = get_option('haman_quick_questions', []);
        if (is_array($qq) && !empty($qq)) $payload['quick_questions'] = $qq;

        if (empty($payload)) {
            wp_send_json_error(['message' => 'هیچ تنظیمات محلی‌ای برای انتقال پیدا نشد / No local settings found to migrate']);
        }

        $r = (new Haman_Api_Client())->update_widget_settings($chatbot_id, $payload);
        if (is_wp_error($r)) {
            self::log('Migrate-to-server failed: ' . $r->get_error_message());
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        self::log('Local content settings migrated to server');
        wp_send_json_success(['message' => 'OK']);
    }

    /** AJAX: pulls the current sync scope from the server, so this site's
     * settings page reflects a change made from the customer portal without
     * requiring the admin to already know one happened. Called when the
     * "همگام‌سازی" tab is opened — see haman-admin.js. */
    public function ajax_get_sync_settings(): void {
        check_ajax_referer('haman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $chatbot_id = get_option('haman_chatbot_id','');
        if (empty($chatbot_id)) {
            wp_send_json_error(['message' => 'ابتدا شناسه‌ی چت‌بات را در تب اتصال تنظیم کنید / Set the Chatbot ID in the Connection tab first']);
        }
        $r = (new Haman_Api_Client())->get_sync_settings($chatbot_id);
        if (is_wp_error($r)) wp_send_json_error(['message' => $r->get_error_message()]);
        wp_send_json_success($r['data'] ?? []);
    }

    /** AJAX: "پاک کردن و ایندکس دوباره" — wipes this chatbot's synced
     * documents server-side, then immediately re-runs a full sync so the
     * index comes back populated with only what the current settings
     * allow, rather than sitting empty until the next scheduled run. */
    public function ajax_clear_and_reindex(): void {
        check_ajax_referer('haman_admin_ajax','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید / No permission'], 403);

        $chatbot_id = get_option('haman_chatbot_id','');
        if (empty($chatbot_id)) {
            wp_send_json_error(['message' => 'ابتدا شناسه‌ی چت‌بات را در تب اتصال تنظیم کنید / Set the Chatbot ID in the Connection tab first']);
        }

        $cleared = (new Haman_Api_Client())->clear_index($chatbot_id);
        if (is_wp_error($cleared)) {
            self::log('Clear index failed: ' . $cleared->get_error_message());
            wp_send_json_error(['message' => $cleared->get_error_message()]);
        }

        $results = (new Haman_Sync_Manager())->run_full_sync();
        set_transient('haman_sync_results', $results, 3600);
        self::log('Index cleared and reindexed: ' . wp_json_encode($cleared['data'] ?? []) . ' / ' . wp_json_encode($results));

        wp_send_json_success([
            'cleared' => $cleared['data'] ?? [],
            'synced'  => $results,
        ]);
    }

    /** AJAX: compares HAMAN_VERSION against the server's advertised
     * latest version (see config('haman.wp_plugin.latest_version') on
     * the Laravel side) — public endpoint, no API key required. */
    public function ajax_check_version(): void {
        check_ajax_referer('haman_admin_ajax','nonce');
        $api_url = rtrim(get_option('haman_api_url', HAMAN_API_BASE), '/');
        $response = wp_remote_get($api_url . '/wp-plugin/latest-version', ['timeout' => 10]);
        if (is_wp_error($response)) wp_send_json_error(['message' => $response->get_error_message()]);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $latest = $body['latest_version'] ?? null;
        wp_send_json_success([
            'current'         => HAMAN_VERSION,
            'latest'          => $latest,
            'update_available' => $latest && version_compare($latest, HAMAN_VERSION, '>'),
        ]);
    }
}
