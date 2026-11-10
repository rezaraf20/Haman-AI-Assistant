<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The widget's chatbot id is printed into every page (HamanWidgetConfig).
 * A page cache keeps serving the old id after the Chatbot ID or API key
 * changes — found on khonehrangi.ir: options pointed at the new chatbot
 * while WP Rocket served 980 cached pages pointing at the old one.
 *
 * So when either option changes, this clears the page cache of whichever
 * cache plugin (or host cache) is active, through that plugin's own public
 * hook. When none is found, or clearing failed, the settings page asks the
 * owner to clear it, and the connection test reads the live home page to
 * check which chatbot it really serves.
 */
class Haman_Page_Cache {
    const NOTICE_OPTION = 'haman_cache_notice';

    private static bool $scheduled = false;

    /** Hooked to update_option_/add_option_ for haman_chatbot_id and haman_api_key. */
    public static function on_identity_changed(): void {
        if ( self::$scheduled ) return; // key + id saved together → clear once
        self::$scheduled = true;
        add_action( 'shutdown', [ __CLASS__, 'purge_and_remember' ] );
    }

    const VERSION_OPTION = 'haman_assets_version';

    /**
     * Hooked to `init`. Cached pages carry the widget's asset URLs with the
     * plugin version in them (?ver=); after an update they would keep
     * serving the old script and stylesheet until the cache expired. So the
     * first request that runs a new version clears the page cache once.
     *
     * Not upgrader_process_complete: during an update the request still
     * runs the OLD plugin code, so a hook added by the new version never
     * fires for the update that installs it.
     */
    public static function on_version_seen( string $version ): void {
        if ( get_option( self::VERSION_OPTION ) === $version ) return;
        update_option( self::VERSION_OPTION, $version, false );
        if ( self::$scheduled ) return;
        self::$scheduled = true;
        add_action( 'shutdown', [ __CLASS__, 'purge_and_remember' ] );
    }

    public static function purge_and_remember(): void {
        $r = self::purge();
        update_option( self::NOTICE_OPTION, [
            'at'     => time(),
            'purged' => $r['purged'],
            'failed' => $r['failed'],
            // Nothing cleared → the owner must; also when one of them failed.
            'needed' => empty( $r['purged'] ) || ! empty( $r['failed'] ),
        ], false );
    }

    /**
     * Every cache this site runs, cleared through its own API.
     * @return array{purged: string[], failed: string[]}
     */
    public static function purge(): array {
        $purged = [];
        $failed = [];
        foreach ( self::purgers() as $name => [ $available, $clear ] ) {
            if ( ! $available() ) continue;
            try {
                $clear();
                $purged[] = $name;
            } catch ( \Throwable $e ) {
                $failed[] = $name;
            }
        }
        return [ 'purged' => $purged, 'failed' => $failed ];
    }

    /** name => [is it active?, clear everything] — each plugin's documented public entry point. */
    public static function purgers(): array {
        return [
            'WP Rocket'          => [ fn () => function_exists( 'rocket_clean_domain' ), function () { rocket_clean_domain(); if ( function_exists( 'rocket_clean_minify' ) ) rocket_clean_minify(); } ],
            'LiteSpeed Cache'    => [ fn () => defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ), fn () => do_action( 'litespeed_purge_all' ) ],
            'W3 Total Cache'     => [ fn () => function_exists( 'w3tc_flush_all' ), fn () => w3tc_flush_all() ],
            'WP Super Cache'     => [ fn () => function_exists( 'wp_cache_clear_cache' ), fn () => wp_cache_clear_cache() ],
            'Autoptimize'        => [ fn () => class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ), fn () => \autoptimizeCache::clearall() ],
            'WP Fastest Cache'   => [ fn () => function_exists( 'wpfc_clear_all_cache' ), fn () => wpfc_clear_all_cache( true ) ],
            'SiteGround'         => [ fn () => function_exists( 'sg_cachepress_purge_cache' ), fn () => sg_cachepress_purge_cache() ],
            'Nginx Helper'       => [ fn () => has_action( 'rt_nginx_helper_purge_all' ), fn () => do_action( 'rt_nginx_helper_purge_all' ) ],
            'Breeze (Cloudways)' => [ fn () => has_action( 'breeze_clear_all_cache' ), fn () => do_action( 'breeze_clear_all_cache' ) ],
            'WP Engine'          => [ fn () => class_exists( 'WpeCommon' ), function () { if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) \WpeCommon::purge_memcached(); if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) \WpeCommon::purge_varnish_cache(); } ],
        ];
    }

    /**
     * The connection test's check, with a repair attempt: when the live page
     * serves another chatbot, clear the page cache now and look again. A
     * stale page outlives the option change whenever the option was written
     * without WordPress's hooks (a migration, wp-cli with plugins skipped,
     * saving an unchanged value) — so waiting for the next change is not
     * enough.
     * @return array{state: string, found: ?string, expected: string, purged: string[]}
     */
    public static function check_and_repair(): array {
        $live = self::check_live_page();
        $live['purged'] = [];
        if ( $live['state'] !== 'mismatch' ) return $live;

        $r = self::purge();
        if ( empty( $r['purged'] ) ) return $live; // nothing we can clear: the notice stays
        $again = self::check_live_page();
        $again['purged'] = $r['purged'];
        return $again;
    }

    /** The chatbot id in a page's HTML (wp_localize_script's HamanWidgetConfig), or null. */
    public static function chatbot_id_in( string $html ): ?string {
        return preg_match( '/HamanWidgetConfig\s*=\s*\{.*?"chatbotId"\s*:\s*"([0-9a-f-]{36})"/is', $html, $m ) ? strtolower( $m[1] ) : null;
    }

    /**
     * Which chatbot the live home page really serves, as a visitor gets it.
     * @return array{state: string, found: ?string, expected: string}
     *   state: ok | mismatch | absent (no widget on the page) | unreachable
     */
    public static function check_live_page(): array {
        $expected = strtolower( (string) get_option( 'haman_chatbot_id', '' ) );
        $r = wp_remote_get( home_url( '/' ), [ 'timeout' => 10, 'redirection' => 3, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] );
        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
            return [ 'state' => 'unreachable', 'found' => null, 'expected' => $expected ];
        }
        $found = self::chatbot_id_in( (string) wp_remote_retrieve_body( $r ) );
        $state = $found === null ? 'absent' : ( $found === $expected ? 'ok' : 'mismatch' );

        // What the page shows settles the notice either way.
        $notice = get_option( self::NOTICE_OPTION, [] );
        if ( $state === 'ok' && ! empty( $notice['needed'] ) ) {
            $notice['needed'] = false;
            update_option( self::NOTICE_OPTION, $notice, false );
        } elseif ( $state === 'mismatch' ) {
            update_option( self::NOTICE_OPTION, [ 'at' => time(), 'purged' => [], 'failed' => [], 'needed' => true ], false );
        }
        return [ 'state' => $state, 'found' => $found, 'expected' => $expected ];
    }
}
