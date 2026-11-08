<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * How many items one sync request may carry — as the platform says, not as
 * this plugin guesses.
 *
 * The platform enforces limits.sync_items_per_request (Admin → Settings) and
 * returns it as `limits` in the connection test and in GET/PUT
 * sync-settings. It is stored here whenever one of those answers, so a
 * change made in the panel takes effect on every site at its next sync,
 * with no plugin release. Until the platform has said anything, a
 * conservative 50.
 *
 * Before 2.7.2 batches were limited by size only (80KB); a shop with many
 * small products sent more than 50 items in one request and the whole
 * batch was refused — khonehrangi.ir delivered 6 of its 173 products.
 */
class Haman_Sync_Limits {
    const OPTION  = 'haman_sync_items_per_request';
    const DEFAULT = 50;
    const MAX     = 1000;

    public static function items_per_request(): int {
        $n = (int) get_option( self::OPTION, 0 );
        return ( $n >= 1 && $n <= self::MAX ) ? $n : self::DEFAULT;
    }

    /** Stores the platform's `limits` from any response that carries them. */
    public static function remember( $data ): void {
        $n = is_array( $data ) ? (int) ( $data['limits']['sync_items_per_request'] ?? 0 ) : 0;
        if ( $n >= 1 && $n <= self::MAX && $n !== (int) get_option( self::OPTION, 0 ) ) {
            update_option( self::OPTION, $n, false );
        }
    }
}
