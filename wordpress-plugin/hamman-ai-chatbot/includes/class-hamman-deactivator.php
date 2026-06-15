<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Hamman_Deactivator {
    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'hamman_hourly_sync' );
    }
}
