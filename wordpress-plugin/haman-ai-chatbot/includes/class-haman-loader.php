<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Haman_Loader {
    private array $actions = [];

    public function run(): void {
        $this->define_hooks();
        foreach ( $this->actions as $h ) {
            add_action( $h['hook'], [ $h['component'], $h['callback'] ], $h['priority'], $h['args'] );
        }
    }

    private function define_hooks(): void {
        $admin  = new Haman_Admin();
        $public = new Haman_Public();
        $sync   = new Haman_Sync_Manager();

        $this->add_action( 'admin_menu',           $admin,  'add_menu_page' );
        $this->add_action( 'admin_enqueue_scripts', $admin,  'enqueue_assets' );
        $this->add_action( 'admin_post_haman_save_settings', $admin, 'save_settings' );
        $this->add_action( 'admin_post_haman_manual_sync',   $admin, 'manual_sync' );
        $this->add_action( 'wp_ajax_haman_test_connection',  $admin, 'ajax_test_connection' );
        $this->add_action( 'wp_ajax_haman_clear_cache',      $admin, 'ajax_clear_cache' );
        $this->add_action( 'wp_ajax_haman_get_webhook_secret',        $admin, 'ajax_get_webhook_secret' );
        $this->add_action( 'wp_ajax_haman_regenerate_webhook_secret', $admin, 'ajax_regenerate_webhook_secret' );
        $this->add_action( 'wp_ajax_haman_get_widget_settings',       $admin, 'ajax_get_widget_settings' );
        $this->add_action( 'wp_ajax_haman_migrate_to_server',         $admin, 'ajax_migrate_to_server' );
        $this->add_action( 'wp_ajax_haman_check_version',             $admin, 'ajax_check_version' );
        $this->add_action( 'wp_enqueue_scripts',   $public, 'enqueue_assets' );
        $this->add_action( 'haman_hourly_sync',   $sync,   'run_incremental_sync' );
        // create_payment_link (doc-04) — auto-cancel rule for unpaid draft
        // orders this integration created; scheduled hourly at activation
        // (see class-haman-activator.php).
        $this->add_action( 'haman_cancel_stale_orders', $sync, 'cancel_stale_draft_orders' );
        $this->add_action( 'rest_api_init',        new Haman_Webhook_Handler(), 'register_routes' );
        $this->add_action( 'rest_api_init',        new Haman_Live_Query_Handler(), 'register_routes' );

        if ( class_exists( 'WooCommerce' ) ) {
            $this->add_action( 'woocommerce_update_product', $sync, 'on_product_updated' );
            $this->add_action( 'woocommerce_delete_product', $sync, 'on_product_deleted' );
            // Revenue attribution (doc-04) — fires once the order actually
            // exists and the customer is on the thank-you page, the
            // standard WooCommerce hook for "a real order was just placed".
            $this->add_action( 'woocommerce_thankyou', $sync, 'on_order_placed' );
            // create_payment_link (doc-04) — keeps a bot-created order's
            // payment status live in the customer portal (paid, cancelled
            // by the auto-cancel cron, or cancelled manually).
            $this->add_action( 'woocommerce_order_status_changed', $sync, 'on_order_status_changed', 10, 3 );
        }
        $this->add_action( 'save_post',    $sync, 'on_post_saved',   10, 3 );
        $this->add_action( 'delete_post',  $sync, 'on_post_removed' );
        $this->add_action( 'wp_trash_post', $sync, 'on_post_removed' );
    }

    private function add_action( string $hook, object $component, string $callback, int $priority = 10, int $args = 1 ): void {
        $this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'args' );
    }
}
