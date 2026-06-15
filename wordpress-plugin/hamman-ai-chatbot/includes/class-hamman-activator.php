<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Activator {
    public static function activate(): void {
        if ( ! wp_next_scheduled( 'hamman_hourly_sync' ) ) {
            wp_schedule_event( time(), 'hourly', 'hamman_hourly_sync' );
        }
        add_option( 'hamman_api_key',        '' );
        add_option( 'hamman_chatbot_id',     '' );
        add_option( 'hamman_api_url',        HAMMAN_API_BASE );
        add_option( 'hamman_webhook_secret', '' );
        add_option( 'hamman_enabled',        '1' );
    }
}
