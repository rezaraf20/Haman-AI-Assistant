<?php
/**
 * The widget's assets, without WordPress. Run: php tests/php/public-assets-test.php
 *
 *  - The stylesheet is NOT enqueued into the page: it is loaded only inside
 *    the widget's own Shadow DOM (haman-widget.js, CFG.cssUrl). It used to be
 *    enqueued as well — loaded twice, and exposed to cache/minify plugins.
 *  - The Shadow DOM stylesheet URL carries the plugin version, so a plugin
 *    update is not served the previous release's CSS from browser cache.
 *  - The first request that runs a new plugin version clears the page cache
 *    once (cached pages carry the versioned asset URLs).
 */
define( 'ABSPATH', __DIR__ );
define( 'HAMAN_VERSION', '9.8.7' );
define( 'HAMAN_PLUGIN_URL', 'https://shop.example/wp-content/plugins/haman-ai-chatbot/' );
define( 'HAMAN_API_BASE', 'https://api.example/api/v1' );

$GLOBALS['opts'] = ['haman_enabled' => '1', 'haman_chatbot_id' => 'bot-1'];
$GLOBALS['styles'] = [];
$GLOBALS['scripts'] = [];
$GLOBALS['localized'] = [];
$GLOBALS['actions'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function add_action( $hook, $cb ) { $GLOBALS['actions'][ $hook ][] = $cb; }
function wp_enqueue_style( $h, $src = '', $deps = [], $ver = false ) { $GLOBALS['styles'][] = $h; }
function wp_enqueue_script( $h, $src = '', $deps = [], $ver = false, $f = false ) { $GLOBALS['scripts'][] = [$h, $src, $ver]; }
function wp_localize_script( $h, $name, $data ) { $GLOBALS['localized'][ $name ] = $data; }
function get_locale() { return 'fa_IR'; }
function rest_url( $p = '' ) { return 'https://shop.example/wp-json/' . $p; }
function wp_create_nonce( $a ) { return 'nonce'; }
require __DIR__ . '/../../public/class-haman-public.php';
require __DIR__ . '/../../includes/class-haman-page-cache.php';

$failures = 0;
function check( bool $ok, string $what ) {
    global $failures;
    echo ( $ok ? 'ok   ' : 'FAIL ' ) . $what . "\n";
    if ( ! $ok ) $failures++;
}

( new Haman_Public() )->enqueue_assets();
$cfg = $GLOBALS['localized']['HamanWidgetConfig'] ?? [];

check( $GLOBALS['styles'] === [], 'the widget stylesheet is not enqueued into the page' );
check( count( $GLOBALS['scripts'] ) === 1 && $GLOBALS['scripts'][0][0] === 'haman-widget' && $GLOBALS['scripts'][0][2] === '9.8.7',
    'the widget script is still enqueued, versioned' );
check( ( $cfg['cssUrl'] ?? '' ) === HAMAN_PLUGIN_URL . 'public/css/haman-widget.css?ver=9.8.7',
    'the Shadow DOM stylesheet URL carries the plugin version' );

// Version change → page cache cleared once, on shutdown.
Haman_Page_Cache::on_version_seen( '9.8.7' );
check( get_option( Haman_Page_Cache::VERSION_OPTION ) === '9.8.7', 'the version seen is remembered' );
check( count( $GLOBALS['actions']['shutdown'] ?? [] ) === 1, 'a new version schedules one cache purge' );
Haman_Page_Cache::on_version_seen( '9.8.7' );
check( count( $GLOBALS['actions']['shutdown'] ?? [] ) === 1, 'the same version again schedules nothing' );

exit( $failures ? 1 : 0 );
