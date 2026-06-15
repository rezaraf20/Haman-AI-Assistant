<?php
/**
 * Plugin Name:       Hamman AI Chatbot
 * Plugin URI:        https://hamman.ir/ai-chatbot
 * Description:       AI-powered chatbot for customer support, sales, and WooCommerce recommendations.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Reza Rafiei
 * Author URI:        https://hamman.ir
 * License:           GPL v2 or later
 * Text Domain:       hamman-ai-chatbot
 * Company:           شرکت هامان فناوران پیشرو
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'HAMMAN_VERSION',    '1.0.0' );
define( 'HAMMAN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAMMAN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HAMMAN_API_BASE',   'http://localhost/api/v1' );

// Autoloader
spl_autoload_register( function( $class ) {
    $prefix = 'Hamman_';
    if ( strpos( $class, $prefix ) !== 0 ) return;
    $dirs = [
        HAMMAN_PLUGIN_DIR . 'includes/',
        HAMMAN_PLUGIN_DIR . 'includes/api/',
        HAMMAN_PLUGIN_DIR . 'includes/sync/',
        HAMMAN_PLUGIN_DIR . 'includes/webhook/',
        HAMMAN_PLUGIN_DIR . 'admin/',
        HAMMAN_PLUGIN_DIR . 'public/',
    ];
    $file = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
    foreach ( $dirs as $dir ) {
        if ( file_exists( $dir . $file ) ) { require_once $dir . $file; return; }
    }
} );

register_activation_hook(   __FILE__, [ 'Hamman_Activator',   'activate' ] );
register_deactivation_hook( __FILE__, [ 'Hamman_Deactivator', 'deactivate' ] );

function hamman_run() {
    ( new Hamman_Loader() )->run();
}
hamman_run();
