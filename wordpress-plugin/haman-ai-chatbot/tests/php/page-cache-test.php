<?php
/**
 * Haman_Page_Cache, without WordPress. Run: php tests/php/page-cache-test.php
 *
 * Found on khonehrangi.ir: the Chatbot ID changed, WP Rocket kept serving
 * 980 cached pages with the old one. Now changing the id or the key clears
 * whichever page cache is active; when none is, or clearing fails, the
 * owner is told; the connection test reads the live page to check.
 *
 * Cache plugins are detected by their functions, which cannot be undefined
 * again — so each scenario runs in its own PHP process (this file calls
 * itself with the scenario name).
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['opts'] = [];
$GLOBALS['actions'] = [];
$GLOBALS['fired'] = [];
$GLOBALS['remote'] = null;
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function add_action( $hook, $cb ) { $GLOBALS['actions'][ $hook ][] = $cb; }
function has_action( $hook ) { return ! empty( $GLOBALS['actions'][ $hook ] ); }
function do_action( $hook ) { $GLOBALS['fired'][] = $hook; foreach ( $GLOBALS['actions'][ $hook ] ?? [] as $cb ) $cb(); }
function home_url( $p = '' ) { return 'https://shop.example' . $p; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {}
function wp_remote_get( $url, $args = [] ) { return isset( $GLOBALS['remote_fn'] ) ? ( $GLOBALS['remote_fn'] )() : $GLOBALS['remote']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
require __DIR__ . '/../../includes/class-haman-page-cache.php';

$scenario = $argv[1] ?? null;
if ( $scenario !== null ) {
    // ── child process: set up one site, report as JSON ──
    if ( $scenario === 'wp_rocket' ) { function rocket_clean_domain() { $GLOBALS['fired'][] = 'rocket_clean_domain'; } }
    if ( $scenario === 'litespeed' ) { add_action( 'litespeed_purge_all', fn () => null ); }
    if ( $scenario === 'failing' )   { function w3tc_flush_all() { throw new RuntimeException( 'w3tc broke' ); } }
    if ( $scenario === 'repair' ) {
        // The option was changed without hooks; the cached page still has the old id.
        function wp_cache_clear_cache() { $GLOBALS['fired'][] = 'wp_cache_clear_cache'; $GLOBALS['cache_cleared'] = true; }
        $GLOBALS['opts']['haman_chatbot_id'] = 'f6ed3b62-ce54-4237-8c23-3bb32b1ae165';
        $page = fn ( $id ) => [ 'code' => 200, 'body' => 'var HamanWidgetConfig = {"chatbotId":"' . $id . '"};' ];
        function wp_remote_get_override() {}
        $GLOBALS['remote_fn'] = fn () => $page( empty( $GLOBALS['cache_cleared'] ) ? '57c6c8d1-2060-421b-8b6a-8a6c1da53d2a' : 'f6ed3b62-ce54-4237-8c23-3bb32b1ae165' );
        echo json_encode( [ 'live' => Haman_Page_Cache::check_and_repair(), 'fired' => $GLOBALS['fired'] ] );
        exit( 0 );
    }
    Haman_Page_Cache::on_identity_changed();
    Haman_Page_Cache::on_identity_changed();   // key + id in one save
    do_action( 'shutdown' );
    echo json_encode( [ 'notice' => get_option( Haman_Page_Cache::NOTICE_OPTION ), 'fired' => $GLOBALS['fired'] ] );
    exit( 0 );
}

$failures = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
    global $failures;
    echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || $detail === '' ? '' : " — {$detail}" ) . "\n";
    if ( ! $ok ) $failures++;
}
function scenario( string $name ): array {
    $out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $name ) );
    return json_decode( (string) $out, true ) ?: [ 'raw' => $out ];
}

$r = scenario( 'wp_rocket' );
check( 'WP Rocket active → cleared through rocket_clean_domain()', ( $r['notice']['purged'] ?? null ) === [ 'WP Rocket' ] && in_array( 'rocket_clean_domain', $r['fired'] ?? [], true ), json_encode( $r ) );
check( 'WP Rocket cleared → no "clear the cache" notice', ( $r['notice']['needed'] ?? null ) === false );
check( 'id + key saved together → cleared once', count( array_keys( $r['fired'] ?? [], 'rocket_clean_domain' ) ) === 1 );

$r = scenario( 'litespeed' );
check( 'LiteSpeed active → litespeed_purge_all fired', ( $r['notice']['purged'] ?? null ) === [ 'LiteSpeed Cache' ] && in_array( 'litespeed_purge_all', $r['fired'] ?? [], true ), json_encode( $r ) );

$r = scenario( 'none' );
check( 'no cache plugin → the owner is asked to clear it', ( $r['notice']['needed'] ?? null ) === true && ( $r['notice']['purged'] ?? null ) === [], json_encode( $r ) );

$r = scenario( 'failing' );
check( 'a cache plugin that fails → named, and the owner is asked', ( $r['notice']['failed'] ?? null ) === [ 'W3 Total Cache' ] && ( $r['notice']['needed'] ?? null ) === true, json_encode( $r ) );

$r = scenario( 'repair' );
check( 'connection test finds a stale page → clears the cache itself and looks again → ok',
    ( $r['live']['state'] ?? null ) === 'ok' && ( $r['live']['purged'] ?? null ) === [ 'WP Super Cache' ] && in_array( 'wp_cache_clear_cache', $r['fired'] ?? [], true ), json_encode( $r ) );

// The id as wp_localize_script prints it.
$page = '<script id="haman-widget-js-extra">var HamanWidgetConfig = {"chatbotId":"F6ED3B62-ce54-4237-8c23-3bb32b1ae165","apiUrl":"https:\/\/api.hamanai.com\/api\/v1"};</script>';
check( 'reads the chatbot id from the page', Haman_Page_Cache::chatbot_id_in( $page ) === 'f6ed3b62-ce54-4237-8c23-3bb32b1ae165' );
check( 'a page without the widget → null', Haman_Page_Cache::chatbot_id_in( '<html>no widget</html>' ) === null );

// The live-page check.
$GLOBALS['opts']['haman_chatbot_id'] = 'f6ed3b62-ce54-4237-8c23-3bb32b1ae165';
$GLOBALS['remote'] = [ 'code' => 200, 'body' => str_replace( 'F6ED3B62-ce54-4237-8c23-3bb32b1ae165', '57c6c8d1-2060-421b-8b6a-8a6c1da53d2a', $page ) ];
$live = Haman_Page_Cache::check_live_page();
check( 'cached page with the old chatbot → mismatch, old id named', $live['state'] === 'mismatch' && $live['found'] === '57c6c8d1-2060-421b-8b6a-8a6c1da53d2a' );
check( 'mismatch → the "clear the cache" notice is raised', ( get_option( Haman_Page_Cache::NOTICE_OPTION )['needed'] ?? null ) === true );
$GLOBALS['remote'] = [ 'code' => 200, 'body' => $page ];
$live = Haman_Page_Cache::check_live_page();
check( 'page serves the configured chatbot → ok', $live['state'] === 'ok' );
check( 'ok → the notice is cleared', ( get_option( Haman_Page_Cache::NOTICE_OPTION )['needed'] ?? null ) === false );
$GLOBALS['remote'] = [ 'code' => 200, 'body' => '<html>widget turned off</html>' ];
check( 'no widget on the page → absent (no false alarm)', Haman_Page_Cache::check_live_page()['state'] === 'absent' );
$GLOBALS['remote'] = new WP_Error();
check( 'site cannot fetch itself → unreachable (no false alarm)', Haman_Page_Cache::check_live_page()['state'] === 'unreachable' );

echo $failures ? "\n{$failures} failed\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
