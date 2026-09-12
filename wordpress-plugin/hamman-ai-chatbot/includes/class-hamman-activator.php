<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Activator {
    public static function activate(): void {
        if ( ! wp_next_scheduled( 'hamman_hourly_sync' ) ) {
            wp_schedule_event( time(), 'hourly', 'hamman_hourly_sync' );
        }
        // create_payment_link (doc-04) — unpaid draft orders this
        // integration created must be auto-cancelled after a hold period,
        // to free up stock a customer never actually paid for. See
        // Hamman_Sync_Manager::cancel_stale_draft_orders().
        if ( ! wp_next_scheduled( 'hamman_cancel_stale_orders' ) ) {
            wp_schedule_event( time(), 'hourly', 'hamman_cancel_stale_orders' );
        }
        add_option( 'hamman_api_key',        '' );
        add_option( 'hamman_chatbot_id',     '' );
        add_option( 'hamman_api_url',        HAMMAN_API_BASE );
        add_option( 'hamman_webhook_secret', '' );
        add_option( 'hamman_enabled',        '1' );
        add_option( 'hamman_payment_link_hold_hours', '24' );
    }
}
