<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Haman_Activator {
    public static function activate(): void {
        // Before anything else, and before the add_option() defaults
        // below: the plugin folder changed name with 2.0.0, so WordPress
        // treats this as a new plugin and the 1.x settings would simply be
        // orphaned. This is the only thing that keeps them.
        Haman_Legacy::migrate_options();
        Haman_Legacy::migrate_meta();
        Haman_Legacy::clear_cron();

        if ( ! wp_next_scheduled( 'haman_hourly_sync' ) ) {
            wp_schedule_event( time(), 'hourly', 'haman_hourly_sync' );
        }
        // create_payment_link (doc-04) — unpaid draft orders this
        // integration created must be auto-cancelled after a hold period,
        // to free up stock a customer never actually paid for. See
        // Haman_Sync_Manager::cancel_stale_draft_orders().
        if ( ! wp_next_scheduled( 'haman_cancel_stale_orders' ) ) {
            wp_schedule_event( time(), 'hourly', 'haman_cancel_stale_orders' );
        }
        add_option( 'haman_api_key',        '' );
        add_option( 'haman_chatbot_id',     '' );
        add_option( 'haman_api_url',        HAMAN_API_BASE );
        add_option( 'haman_webhook_secret', '' );
        add_option( 'haman_enabled',        '1' );
        add_option( 'haman_payment_link_hold_hours', '24' );
    }
}
