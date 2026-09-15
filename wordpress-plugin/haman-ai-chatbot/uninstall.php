<?php
/**
 * Runs only on actual plugin *deletion* from the Plugins screen (WordPress
 * core requirement — never on deactivation), and only clears this plugin's
 * own local WordPress options if the site admin explicitly opted into that
 * via the "حذف اطلاعات هنگام حذف افزونه" checkbox in the Advanced tab
 * (haman_delete_data_on_uninstall) — leaving settings in place by default
 * is the safer choice for anyone who deletes-then-reinstalls to troubleshoot.
 * Data on the Haman server (conversations, leads, synced content) is never
 * touched from here regardless — this can only reach the local WP database.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

if ( get_option( 'haman_delete_data_on_uninstall', '0' ) !== '1' ) return;

$options = [
    'haman_api_key', 'haman_chatbot_id', 'haman_webhook_secret', 'haman_api_url', 'haman_enabled',
    'haman_primary_color', 'haman_widget_position', 'haman_avatar_url',
    'haman_auto_reply_enabled', 'haman_ai_name', 'haman_chat_title', 'haman_welcome_text',
    'haman_input_placeholder', 'haman_system_instruction', 'haman_fallback_response',
    'haman_rate_limit_max_messages', 'haman_rate_limit_block_minutes',
    'haman_lead_capture_enabled', 'haman_lead_capture_prompt', 'haman_lead_capture_thanks', 'haman_lead_capture_invalid',
    'haman_quick_questions',
    'haman_sync_products', 'haman_sync_pages', 'haman_sync_pdfs',
    'haman_last_full_sync', 'haman_delete_data_on_uninstall',
    'haman_debug_log',
];
foreach ( $options as $option ) {
    delete_option( $option );
}

// A site that upgraded from 1.x may still hold keys under the old spelling
// if activation never completed. Opting into deletion should leave nothing.
require_once __DIR__ . '/includes/class-haman-legacy.php';
foreach ( Haman_Legacy::uninstall_options() as $option ) {
    delete_option( $option );
}

delete_transient( 'haman_sync_results' );
delete_transient( 'haman_settings_push_error' );
delete_transient( 'haman_webhook_secret_cache' );
