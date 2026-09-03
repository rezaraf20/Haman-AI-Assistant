<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Loader {
    private array $actions = [];

    public function run(): void {
        $this->define_hooks();
        foreach ( $this->actions as $h ) {
            add_action( $h['hook'], [ $h['component'], $h['callback'] ], $h['priority'], $h['args'] );
        }
    }

    private function define_hooks(): void {
        $admin  = new Hamman_Admin();
        $public = new Hamman_Public();
        $sync   = new Hamman_Sync_Manager();

        $this->add_action( 'admin_menu',           $admin,  'add_menu_page' );
        $this->add_action( 'admin_enqueue_scripts', $admin,  'enqueue_assets' );
        $this->add_action( 'admin_post_hamman_save_settings', $admin, 'save_settings' );
        $this->add_action( 'admin_post_hamman_manual_sync',   $admin, 'manual_sync' );
        $this->add_action( 'wp_enqueue_scripts',   $public, 'enqueue_assets' );
        $this->add_action( 'hamman_hourly_sync',   $sync,   'run_incremental_sync' );
        $this->add_action( 'rest_api_init',        new Hamman_Webhook_Handler(), 'register_routes' );

        if ( class_exists( 'WooCommerce' ) ) {
            $this->add_action( 'woocommerce_update_product', $sync, 'on_product_updated' );
            $this->add_action( 'woocommerce_delete_product', $sync, 'on_product_deleted' );
        }
        $this->add_action( 'save_post', $sync, 'on_post_saved', 10, 3 );
    }

    private function add_action( string $hook, object $component, string $callback, int $priority = 10, int $args = 1 ): void {
        $this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'args' );
    }
}
