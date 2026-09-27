<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The one place that knows the five outcome keys the admin results table
 * (settings-page.php) displays. Used by Haman_Product_Sync/Haman_Page_Sync/
 * Haman_Faq_Sync to accumulate what the platform actually did with each
 * batch (SyncJob.result, via SyncController::jobArr()'s 'result' key)
 * instead of a flat "how many did I POST" count that had no relationship
 * to new/updated/skipped/deleted/failed — the mismatch that made the
 * results table always show zero regardless of what a sync actually did.
 */
class Haman_Sync_Counts {
    const KEYS = [ 'new', 'updated', 'skipped', 'deleted', 'failed' ];

    public static function empty(): array {
        return array_fill_keys( self::KEYS, 0 );
    }

    /** Adds one batch's platform-reported result onto the running totals. */
    public static function add( array &$totals, array $result ): void {
        foreach ( self::KEYS as $key ) {
            $totals[ $key ] += (int) ( $result[ $key ] ?? 0 );
        }
    }
}
