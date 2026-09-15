<?php
/**
 * Plugin Name:       Haman AI Chatbot
 * Plugin URI:        https://haman.ir/ai-chatbot
 * Description:       AI-powered chatbot for customer support, sales, and WooCommerce recommendations.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Reza Rafiei
 * Author URI:        https://haman.ir
 * License:           GPL v2 or later
 * Text Domain:       haman-ai-chatbot
 * Company:           شرکت هامان فناوران پیشرو
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'HAMAN_VERSION',    '2.0.0' );
define( 'HAMAN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAMAN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HAMAN_API_BASE',   'https://api.arshanweb.ir/api/v1' );

// Autoloader
spl_autoload_register( function( $class ) {
    $prefix = 'Haman_';
    if ( strpos( $class, $prefix ) !== 0 ) return;
    $dirs = [
        HAMAN_PLUGIN_DIR . 'includes/',
        HAMAN_PLUGIN_DIR . 'includes/api/',
        HAMAN_PLUGIN_DIR . 'includes/sync/',
        HAMAN_PLUGIN_DIR . 'includes/webhook/',
        HAMAN_PLUGIN_DIR . 'admin/',
        HAMAN_PLUGIN_DIR . 'public/',
    ];
    $file = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
    foreach ( $dirs as $dir ) {
        if ( file_exists( $dir . $file ) ) { require_once $dir . $file; return; }
    }
} );

register_activation_hook(   __FILE__, [ 'Haman_Activator',   'activate' ] );
register_deactivation_hook( __FILE__, [ 'Haman_Deactivator', 'deactivate' ] );

function haman_run() {
    ( new Haman_Loader() )->run();
}
haman_run();
