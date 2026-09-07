<?php
/**
 * Runs only on actual plugin *deletion* from the Plugins screen (WordPress
 * core requirement — never on deactivation), and only clears this plugin's
 * own local WordPress options if the site admin explicitly opted into that
 * via the "حذف اطلاعات هنگام حذف افزونه" checkbox in the Advanced tab
 * (hamman_delete_data_on_uninstall) — leaving settings in place by default
 * is the safer choice for anyone who deletes-then-reinstalls to troubleshoot.
 * Data on the Hamman server (conversations, leads, synced content) is never
 * touched from here regardless — this can only reach the local WP database.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

if ( get_option( 'hamman_delete_data_on_uninstall', '0' ) !== '1' ) return;

$options = [
    'hamman_api_key', 'hamman_chatbot_id', 'hamman_webhook_secret', 'hamman_api_url', 'hamman_enabled',
    'hamman_primary_color', 'hamman_widget_position', 'hamman_avatar_url',
    'hamman_auto_reply_enabled', 'hamman_ai_name', 'hamman_chat_title', 'hamman_welcome_text',
    'hamman_input_placeholder', 'hamman_system_instruction', 'hamman_fallback_response',
    'hamman_rate_limit_max_messages', 'hamman_rate_limit_block_minutes',
    'hamman_lead_capture_enabled', 'hamman_lead_capture_prompt', 'hamman_lead_capture_thanks', 'hamman_lead_capture_invalid',
    'hamman_quick_questions',
    'hamman_sync_products', 'hamman_sync_pages', 'hamman_sync_pdfs',
    'hamman_last_full_sync', 'hamman_delete_data_on_uninstall',
    'hamman_debug_log',
];
foreach ( $options as $option ) {
    delete_option( $option );
}

delete_transient( 'hamman_sync_results' );
delete_transient( 'hamman_settings_push_error' );
delete_transient( 'hamman_webhook_secret_cache' );
