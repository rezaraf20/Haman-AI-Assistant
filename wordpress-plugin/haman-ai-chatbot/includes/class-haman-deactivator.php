<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Haman_Deactivator {
    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'haman_hourly_sync' );
        wp_clear_scheduled_hook( 'haman_cancel_stale_orders' );
    }
}
