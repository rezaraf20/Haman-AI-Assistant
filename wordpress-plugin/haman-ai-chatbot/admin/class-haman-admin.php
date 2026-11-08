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
 *
 * Every string a site owner reads goes through __() with the
 * 'haman-ai-chatbot' text domain: English source, Persian in
 * languages/haman-ai-chatbot-fa_IR.po, so the page follows the admin's own
 * language and direction instead of printing both languages side by side.
 * Log lines stay English — they are for support, not the page.
 */
class Haman_Admin {
    const LOG_OPTION = 'haman_debug_log';
    const LOG_MAX_ENTRIES = 100;
    const LATEST_VERSION_TRANSIENT = 'haman_latest_version';

    public function add_menu_page(): void {
        add_menu_page('Haman AI', 'Haman AI', 'manage_options', 'haman-ai-chatbot', [$this, 'render'], 'dashicons-format-chat', 30);
    }

    public function enqueue_assets( string $hook ): void {
        if (strpos($hook, 'haman-ai-chatbot') === false) return;
        wp_enqueue_style('haman-admin', HAMAN_PLUGIN_URL . 'admin/css/haman-admin.css', [], HAMAN_VERSION);
        wp_enqueue_script('haman-admin', HAMAN_PLUGIN_URL . 'admin/js/haman-admin.js', [], HAMAN_VERSION, true);
        wp_localize_script('haman-admin', 'HamanAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('haman_admin_ajax'),
            'i18n'    => $this->js_strings(),
        ]);
    }

    /** Strings the page's JavaScript builds into the DOM itself. */
    private function js_strings(): array {
        return [
            'checking'          => __('Checking…', 'haman-ai-chatbot'),
            'loading'           => __('Loading…', 'haman-ai-chatbot'),
            'unreachable'       => __('Could not reach the WordPress server. Reload the page and try again.', 'haman-ai-chatbot'),
            'error'             => __('Error', 'haman-ai-chatbot'),
            'connectedTo'       => __('Connected to “%s”', 'haman-ai-chatbot'),
            'stateConnected'    => __('Connected', 'haman-ai-chatbot'),
            'stateInvalidKey'   => __('Invalid key', 'haman-ai-chatbot'),
            'stateSuspended'    => __('Chatbot suspended', 'haman-ai-chatbot'),
            'stateAwaitingDomain' => __('Awaiting domain registration', 'haman-ai-chatbot'),
            'stateDisconnected' => __('Not connected', 'haman-ai-chatbot'),
            'stateNotSet'       => __('Not set up', 'haman-ai-chatbot'),
            'documentsTotal'    => __('%1$s of %2$s documents', 'haman-ai-chatbot'),
            'documentsNoLimit'  => __('%s documents', 'haman-ai-chatbot'),
            'documentsUnknown'  => __('Unavailable until connected', 'haman-ai-chatbot'),
            'overLimit'         => __('%s documents were not indexed because your plan’s document limit was reached. The bot does not answer from them; upgrading, or turning off types you don’t need, lets the next sync add them.', 'haman-ai-chatbot'),
            'noDocuments'       => __('Nothing has been synced yet. Run a full sync below.', 'haman-ai-chatbot'),
            'kindProducts'      => __('Products', 'haman-ai-chatbot'),
            'kindPdfs'          => __('Product PDFs', 'haman-ai-chatbot'),
            'kindPages'         => __('Pages', 'haman-ai-chatbot'),
            'kindPosts'         => __('Posts', 'haman-ai-chatbot'),
            'kindFaqs'          => __('FAQs', 'haman-ai-chatbot'),
            'never'             => __('Never', 'haman-ai-chatbot'),
            'neverSynced'       => __('content has never been synced', 'haman-ai-chatbot'),
            'widgetOff'         => __('the chat widget is turned off on this site', 'haman-ai-chatbot'),
            /* translators: %s: number of documents */
            'overLimitShort'    => __('the last sync left out %s documents (plan limit)', 'haman-ai-chatbot'),
            'allGood'           => __('Everything is working: connected, synced and the widget is live.', 'haman-ai-chatbot'),
            /* translators: %s: a list of problems separated by " · " */
            'needsAttention'    => __('Needs attention: %s', 'haman-ai-chatbot'),
            'colDocuments'      => __('Documents', 'haman-ai-chatbot'),
            'colKind'           => __('Content type', 'haman-ai-chatbot'),
            /* translators: %s: version number, e.g. 2.7.0 */
            'updateAvailable'   => __('Version %s is available.', 'haman-ai-chatbot'),
            'download'          => __('Download', 'haman-ai-chatbot'),
            'upToDate'          => __('You have the latest version.', 'haman-ai-chatbot'),
            'show'              => __('Show', 'haman-ai-chatbot'),
            'hide'              => __('Hide', 'haman-ai-chatbot'),
            'copied'            => __('Copied', 'haman-ai-chatbot'),
            'copyFailed'        => __('Copy failed — select the key and copy it manually.', 'haman-ai-chatbot'),
            'syncStep'          => __('Step %1$s of %2$s: %3$s', 'haman-ai-chatbot'),
            'syncPrepare'       => __('Reading settings from Haman', 'haman-ai-chatbot'),
            'syncProducts'      => __('Products', 'haman-ai-chatbot'),
            'syncPages'         => __('Pages and site content', 'haman-ai-chatbot'),
            'syncFaqs'          => __('FAQs', 'haman-ai-chatbot'),
            'syncDone'          => __('Sync finished.', 'haman-ai-chatbot'),
            'syncFailed'        => __('Sync stopped: %s', 'haman-ai-chatbot'),
            'syncSummary'       => __('%1$s new, %2$s updated, %3$s unchanged, %4$s failed', 'haman-ai-chatbot'),
            'confirmRegenerate' => __('The current webhook secret will stop working immediately. Continue?', 'haman-ai-chatbot'),
            'confirmMigrate'    => __('This sends your current local WordPress settings to Haman and may overwrite what is set in the Haman portal. Continue?', 'haman-ai-chatbot'),
            'confirmReindex'    => __('This deletes everything currently synced and rebuilds it with the settings above. FAQs you entered by hand are not affected. Continue?', 'haman-ai-chatbot'),
            'migrated'          => __('Migrated', 'haman-ai-chatbot'),
            'cleared'           => __('Cleared', 'haman-ai-chatbot'),
            'reindexed'         => __('%1$s documents and %2$s chunks removed; a fresh sync has run.', 'haman-ai-chatbot'),
            'fieldsNone'        => __('No fields will be sent.', 'haman-ai-chatbot'),
            'fieldsSent'        => __('These fields will be sent: %s', 'haman-ai-chatbot'),
            'yes'               => __('Yes', 'haman-ai-chatbot'),
            'no'                => __('No', 'haman-ai-chatbot'),
            'widgetFields'      => [
                'welcome_message'      => __('Welcome message', 'haman-ai-chatbot'),
                'chat_title'           => __('Chat window title', 'haman-ai-chatbot'),
                'ai_name'              => __('Assistant name', 'haman-ai-chatbot'),
                'avatar_url'           => __('Avatar URL', 'haman-ai-chatbot'),
                'primary_color'        => __('Primary colour', 'haman-ai-chatbot'),
                'position'             => __('Position on the page', 'haman-ai-chatbot'),
                'system_instruction'   => __('System instruction', 'haman-ai-chatbot'),
                'quick_questions'      => __('Quick questions', 'haman-ai-chatbot'),
                'lead_capture_enabled' => __('Lead capture', 'haman-ai-chatbot'),
            ],
        ];
    }

    public function render(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to change these settings.', 'haman-ai-chatbot'));
        // Show what the platform currently has (it is authoritative — a type
        // may have been switched on or off from the customer portal). At most
        // once a minute, so the settings page isn't waiting on the API on
        // every load; a failed pull simply shows the local copy.
        if (false === get_transient('haman_sync_settings_pulled')) {
            Haman_Sync_Settings::pull_from_server();
            set_transient('haman_sync_settings_pulled', 1, MINUTE_IN_SECONDS);
        }
        include HAMAN_PLUGIN_DIR . 'admin/partials/settings-page.php';
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

    private function deny_unless_admin(): void {
        check_ajax_referer('haman_admin_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to change these settings.', 'haman-ai-chatbot')], 403);
        }
    }

    private function require_chatbot_id(): string {
        $chatbot_id = get_option('haman_chatbot_id', '');
        if (empty($chatbot_id)) {
            wp_send_json_error(['message' => __('Set the Chatbot ID on the Connection tab first.', 'haman-ai-chatbot')]);
        }
        return $chatbot_id;
    }

    public function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to change these settings.', 'haman-ai-chatbot'));
        check_admin_referer('haman_save_settings');

        // Connection
        update_option('haman_api_key',       sanitize_text_field($_POST['haman_api_key'] ?? ''));
        update_option('haman_chatbot_id',    sanitize_text_field($_POST['haman_chatbot_id'] ?? ''));
        update_option('haman_api_url',       esc_url_raw($_POST['haman_api_url'] ?? HAMAN_API_BASE));
        update_option('haman_enabled',       isset($_POST['haman_enabled']) ? '1' : '0');
        // The status strip's connection result was for the old values.
        delete_transient('haman_connection_status');

        // Sync scope: products on their own toggle, every other post type
        // (page, post, custom types) as one checked list — see
        // Haman_Sync_Settings. Only types this site actually has are kept.
        update_option('haman_sync_products', isset($_POST['haman_sync_products']) ? '1' : '0');
        $available = array_keys(Haman_Sync_Settings::available_post_types());
        $picked    = array_map('sanitize_key', (array) wp_unslash($_POST['haman_sync_post_types'] ?? []));
        Haman_Sync_Settings::set_enabled_post_types(array_values(array_intersect($picked, $available)));

        // Custom fields: only keys the owner ticked in the preview, per type.
        // Haman_Sync_Settings drops any "_"-prefixed (private) key regardless.
        $fields = [];
        foreach ((array) wp_unslash($_POST['haman_sync_custom_fields'] ?? []) as $type => $keys) {
            if (!in_array(sanitize_key($type), $available, true)) continue;
            $fields[sanitize_key($type)] = array_map('sanitize_text_field', (array) $keys);
        }
        Haman_Sync_Settings::set_custom_fields($fields);
        update_option('haman_sync_pdfs',     isset($_POST['haman_sync_pdfs']) ? '1' : '0');
        update_option('haman_sync_excluded_category_ids', $this->sanitize_id_list($_POST['haman_sync_excluded_category_ids'] ?? ''));
        update_option('haman_sync_excluded_page_ids',     $this->sanitize_id_list($_POST['haman_sync_excluded_page_ids'] ?? ''));

        // Best-effort push to the server, so a change made here also shows
        // up in the customer portal's SyncSettings page without the admin
        // having to open that separately. Never blocks the redirect below: a
        // merchant without a working API connection yet must still be able
        // to save local settings.
        if (!empty(get_option('haman_chatbot_id', ''))) {
            $pushed = Haman_Sync_Settings::push_to_server();
            if (is_array($pushed) && !empty($pushed['archived_documents'])) {
                self::log('Sync settings saved; the platform archived ' . (int) $pushed['archived_documents'] . ' document(s) of the types you turned off.');
            }
            delete_transient('haman_sync_settings_pulled');
        }

        // Authenticity field mapping — see Haman_Product_Sync::
        // AUTHENTICITY_FIELDS/authenticity_fields(). An entry is only kept
        // when both a real type AND a non-empty key were given; anything else
        // means "not mapped", read back as null at sync time.
        $field_mapping = [];
        foreach ( Haman_Product_Sync::AUTHENTICITY_FIELDS as $field ) {
            $type = sanitize_key( $_POST["haman_field_map_{$field}_type"] ?? '' );
            $key  = sanitize_text_field( $_POST["haman_field_map_{$field}_key"] ?? '' );
            if ( in_array( $type, [ 'meta', 'attribute' ], true ) && '' !== $key ) {
                $field_mapping[ $field ] = [ 'type' => $type, 'key' => $key ];
            }
        }
        update_option( 'haman_field_mapping', $field_mapping );

        // Advanced (rate limiting stays local — it's per-visitor-IP on this
        // specific site, not a server-side/content setting)
        update_option('haman_rate_limit_max_messages',  max(1, (int) ($_POST['haman_rate_limit_max_messages'] ?? 50)));
        update_option('haman_rate_limit_block_minutes', max(1, (int) ($_POST['haman_rate_limit_block_minutes'] ?? 15)));
        update_option('haman_delete_data_on_uninstall', isset($_POST['haman_delete_data_on_uninstall']) ? '1' : '0');

        $tab = sanitize_key($_POST['haman_active_tab'] ?? 'connection');
        wp_redirect(admin_url('admin.php?page=haman-ai-chatbot&saved=1&tab=' . $tab));
        exit;
    }

    /** No-JavaScript fallback for the Sync tab's button (the page normally runs the steps over AJAX). */
    public function manual_sync(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to change these settings.', 'haman-ai-chatbot'));
        check_admin_referer('haman_manual_sync');
        $results = (new Haman_Sync_Manager())->run_full_sync();
        $this->record_sync_results($results);
        wp_redirect(admin_url('admin.php?page=haman-ai-chatbot&synced=1&tab=sync'));
        exit;
    }

    private function record_sync_results( array $results ): void {
        set_transient('haman_sync_results', $results, HOUR_IN_SECONDS);
        if (!empty($results['error'])) {
            self::log('Manual sync failed: ' . $results['error']);
            return;
        }
        foreach ($results as $type => $r) {
            if (is_array($r) && !empty($r['errors'])) self::log("Sync ({$type}) completed with errors: " . wp_json_encode($r['errors']));
        }
        self::log('Manual sync completed: ' . wp_json_encode($results));
    }

    /**
     * AJAX: one step of a manual full sync — prepare, products, pages, faqs,
     * finish — so the page can show real progress ("step 2 of 3: pages")
     * instead of a frozen button for however long a big catalogue takes.
     * The same Haman_Sync_Manager the scheduled sync uses does the work.
     */
    public function ajax_sync_step(): void {
        $this->deny_unless_admin();
        $step = sanitize_key($_POST['step'] ?? '');
        $manager = new Haman_Sync_Manager();

        if ($step === 'prepare') {
            if (!$manager->is_ready()) {
                wp_send_json_error(['message' => __('The connection is not set up yet: enter the API key and Chatbot ID on the Connection tab and save.', 'haman-ai-chatbot')]);
            }
            Haman_Sync_Settings::pull_from_server();
            delete_transient('haman_sync_progress');
            wp_send_json_success(['steps' => $manager->full_sync_steps()]);
        }

        if (in_array($step, ['products', 'pages', 'faqs'], true)) {
            $result = $manager->run_sync_step($step);
            $progress = get_transient('haman_sync_progress') ?: [];
            $progress[$step] = $result;
            set_transient('haman_sync_progress', $progress, HOUR_IN_SECONDS);
            wp_send_json_success(['result' => $result]);
        }

        if ($step === 'finish') {
            $progress = get_transient('haman_sync_progress') ?: [];
            update_option('haman_last_full_sync', time());
            $this->record_sync_results($progress);
            delete_transient('haman_sync_progress');
            delete_transient('haman_knowledge_cache');
            wp_send_json_success(['results' => $progress, 'last_sync' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), time())]);
        }

        wp_send_json_error(['message' => __('Unknown sync step.', 'haman-ai-chatbot')]);
    }

    /**
     * AJAX: everything the status strip shows — connection (with the exact
     * reason when it isn't connected), what is indexed by kind, and whether
     * the widget is actually live on this site. The connection result is
     * cached for five minutes so opening the page doesn't call the API on
     * every load; "Re-check" and a settings save both bypass it.
     */
    public function ajax_status(): void {
        $this->deny_unless_admin();
        $fresh = !empty($_POST['fresh']);
        $client = new Haman_Api_Client();

        $configured = !empty(get_option('haman_api_key')) && !empty(get_option('haman_chatbot_id'));
        $connection = $fresh ? false : get_transient('haman_connection_status');
        if ($connection === false) {
            if (!$configured) {
                $connection = ['state' => 'not_set', 'message' => __('Enter the API key and Chatbot ID from your Haman panel, then save.', 'haman-ai-chatbot')];
            } else {
                $r = $client->test_connection();
                $connection = is_wp_error($r)
                    ? ['state' => self::connection_state($r->get_error_code()), 'message' => $r->get_error_message(), 'reason' => $r->get_error_code()]
                    : ['state' => 'connected', 'message' => $r['message'], 'name' => $r['name'] ?? ''];
                self::log('Connection check: ' . $connection['state'] . (isset($connection['reason']) ? ' (' . $connection['reason'] . ')' : ''));
            }
            set_transient('haman_connection_status', $connection, 5 * MINUTE_IN_SECONDS);
        }

        $knowledge = null;
        if ($connection['state'] === 'connected') {
            $knowledge = $fresh ? false : get_transient('haman_knowledge_cache');
            if ($knowledge === false) {
                $k = $client->get_knowledge(get_option('haman_chatbot_id', ''));
                $knowledge = is_wp_error($k) ? null : ($k['data'] ?? null);
                if ($knowledge !== null) set_transient('haman_knowledge_cache', $knowledge, 5 * MINUTE_IN_SECONDS);
            }
        }

        $last = (int) get_option('haman_last_full_sync', 0);
        wp_send_json_success([
            'connection' => $connection,
            'knowledge'  => $knowledge,
            'widget'     => [
                'enabled'    => get_option('haman_enabled', '1') === '1',
                'configured' => $configured,
            ],
            'last_sync'  => $last ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last) : null,
        ]);
    }

    /** test_connection()'s WP_Error codes, grouped into what the status strip can say. */
    public static function connection_state( string $code ): string {
        return match ($code) {
            'invalid_key', 'missing_key', 'key_expired', 'no_key', 'account_error', 'key_unbound' => 'invalid_key',
            'chatbot_suspended' => 'suspended',
            // The key works, but the chatbot has no registered domain yet, so
            // its widget answers nobody (one chatbot = one domain).
            'awaiting_domain' => 'awaiting_domain',
            default => 'disconnected',
        };
    }

    /** AJAX: "Test connection" — the same check as the status strip, always fresh, with the precise reason. */
    public function ajax_test_connection(): void {
        $this->deny_unless_admin();

        $result = (new Haman_Api_Client())->test_connection();
        delete_transient('haman_connection_status');
        if (is_wp_error($result)) {
            self::log('Connection test failed (' . $result->get_error_code() . '): ' . $result->get_error_message());
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'reason'  => $result->get_error_code(),
                'state'   => self::connection_state($result->get_error_code()),
            ]);
        }
        self::log('Connection test succeeded: ' . $result['message']);
        wp_send_json_success(['message' => $result['message'], 'name' => $result['name'] ?? '']);
    }

    public function ajax_clear_cache(): void {
        $this->deny_unless_admin();

        delete_transient('haman_sync_results');
        delete_transient('haman_settings_push_error');
        delete_transient('haman_webhook_secret_cache');
        delete_transient('haman_connection_status');
        delete_transient('haman_knowledge_cache');
        delete_transient(self::LATEST_VERSION_TRANSIENT);
        wp_cache_flush_group('haman');
        self::log('Cache cleared by admin');
        wp_send_json_success(['message' => 'OK']);
    }

    /** AJAX: fetches this chatbot's real webhook secret from the server
     * (public schema tenants.settings — not stored locally at all anymore,
     * so a customer can never see a stale copy). */
    public function ajax_get_webhook_secret(): void {
        $this->deny_unless_admin();

        $secret = (new Haman_Api_Client())->get_webhook_secret();
        if (is_wp_error($secret)) wp_send_json_error(['message' => $secret->get_error_message()]);
        wp_send_json_success(['secret' => $secret]);
    }

    /** AJAX: "Regenerate" — the old secret stops verifying immediately;
     * requires explicit confirmation client-side since it can break
     * in-flight webhook deliveries signed with the previous value. */
    public function ajax_regenerate_webhook_secret(): void {
        $this->deny_unless_admin();

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
     * server for the read-only display on the Appearance tab. */
    public function ajax_get_widget_settings(): void {
        $this->deny_unless_admin();
        $chatbot_id = $this->require_chatbot_id();

        $r = (new Haman_Api_Client())->get_widget_settings_for_display($chatbot_id);
        if (is_wp_error($r)) wp_send_json_error(['message' => $r->get_error_message()]);
        wp_send_json_success($r);
    }

    /** AJAX: one-time push of this site's local content settings to the
     * server — only relevant for a site that was on an older plugin
     * version where these fields were still stored/edited locally.
     * Requires the admin's explicit confirmation client-side since it can
     * overwrite whatever's currently configured in the customer portal. */
    public function ajax_migrate_to_server(): void {
        $this->deny_unless_admin();
        $chatbot_id = $this->require_chatbot_id();

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
            wp_send_json_error(['message' => __('No local settings were found to migrate.', 'haman-ai-chatbot')]);
        }

        $r = (new Haman_Api_Client())->update_widget_settings($chatbot_id, $payload);
        if (is_wp_error($r)) {
            self::log('Migrate-to-server failed: ' . $r->get_error_message());
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        self::log('Local content settings migrated to server');
        wp_send_json_success(['message' => 'OK']);
    }

    /** AJAX: pulls the current sync scope from the server. Kept for any
     * caller that wants the raw value; the page itself refreshes it on
     * render (see render()). */
    public function ajax_get_sync_settings(): void {
        $this->deny_unless_admin();
        $chatbot_id = $this->require_chatbot_id();
        $r = (new Haman_Api_Client())->get_sync_settings($chatbot_id);
        if (is_wp_error($r)) wp_send_json_error(['message' => $r->get_error_message()]);
        wp_send_json_success($r['data'] ?? []);
    }

    /** AJAX: "Clear and reindex" — wipes this chatbot's synced documents
     * server-side, then immediately re-runs a full sync so the index comes
     * back populated with only what the current settings allow, rather
     * than sitting empty until the next scheduled run. */
    public function ajax_clear_and_reindex(): void {
        $this->deny_unless_admin();
        $chatbot_id = $this->require_chatbot_id();

        $cleared = (new Haman_Api_Client())->clear_index($chatbot_id);
        if (is_wp_error($cleared)) {
            self::log('Clear index failed: ' . $cleared->get_error_message());
            wp_send_json_error(['message' => $cleared->get_error_message()]);
        }

        $results = (new Haman_Sync_Manager())->run_full_sync();
        $this->record_sync_results($results);
        delete_transient('haman_knowledge_cache');
        self::log('Index cleared and reindexed: ' . wp_json_encode($cleared['data'] ?? []));

        wp_send_json_success([
            'cleared' => $cleared['data'] ?? [],
            'synced'  => $results,
        ]);
    }

    /**
     * The newest plugin version the platform publishes, cached for a day:
     * {latest_version, download_url} from the public, keyless
     * GET /wp-plugin/latest-version. Null when it can't be read — never a
     * guessed version. There is no self-update, so this is what tells a
     * site owner that one exists and where to get it.
     */
    public static function latest_version(): ?array {
        $cached = get_transient(self::LATEST_VERSION_TRANSIENT);
        if (is_array($cached)) return $cached;

        $api_url = rtrim(get_option('haman_api_url', HAMAN_API_BASE), '/');
        $response = wp_remote_get($api_url . '/wp-plugin/latest-version', ['timeout' => 10]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::LATEST_VERSION_TRANSIENT, [], HOUR_IN_SECONDS);
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $info = [
            'latest_version' => is_string($body['latest_version'] ?? null) ? $body['latest_version'] : null,
            'download_url'   => is_string($body['download_url'] ?? null) ? esc_url_raw($body['download_url']) : null,
        ];
        set_transient(self::LATEST_VERSION_TRANSIENT, $info, DAY_IN_SECONDS);
        return $info;
    }

    /**
     * "Version X is available", on the Dashboard, the Plugins screen and
     * this plugin's own page — the places an admin actually looks. Only for
     * people who can install plugins, and only when the platform reports a
     * strictly newer version.
     */
    public function update_notice(): void {
        if (!current_user_can('install_plugins')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['dashboard', 'plugins', 'toplevel_page_haman-ai-chatbot'], true)) return;

        $info = self::latest_version();
        $latest = $info['latest_version'] ?? null;
        if (!$latest || !version_compare($latest, HAMAN_VERSION, '>')) return;

        $link = !empty($info['download_url'])
            ? ' <a href="' . esc_url($info['download_url']) . '">' . esc_html__('Download the new version', 'haman-ai-chatbot') . '</a>'
            : '';
        echo '<div class="notice notice-warning"><p>'
            . esc_html(sprintf(
                /* translators: 1: newer version, 2: installed version */
                __('Haman AI Chatbot %1$s is available — you have %2$s. Upload the new ZIP from Plugins → Add New → Upload Plugin.', 'haman-ai-chatbot'),
                $latest, HAMAN_VERSION
            ))
            . $link . '</p></div>';
    }

    /** AJAX: the version line on the Advanced tab — the same cached lookup as the notice. */
    public function ajax_check_version(): void {
        $this->deny_unless_admin();
        $info = self::latest_version();
        $latest = $info['latest_version'] ?? null;
        wp_send_json_success([
            'current'          => HAMAN_VERSION,
            'latest'           => $latest,
            'download_url'     => $info['download_url'] ?? null,
            'update_available' => $latest && version_compare($latest, HAMAN_VERSION, '>'),
        ]);
    }
}
