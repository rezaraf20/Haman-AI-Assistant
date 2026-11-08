<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The one place that knows the outcome keys the admin results table
 * (settings-page.php) displays. Used by Haman_Product_Sync/Haman_Page_Sync/
 * Haman_Faq_Sync to accumulate what the platform actually did with each
 * batch (SyncJob.result, via SyncController::jobArr()'s 'result' key)
 * instead of a flat "how many did I POST" count that had no relationship
 * to new/updated/skipped/deleted/failed — the mismatch that made the
 * results table always show zero regardless of what a sync actually did.
 */
class Haman_Sync_Counts {
    // over_limit: items the plan's document limit refused (platform 2026-11+).
    const KEYS = [ 'new', 'updated', 'skipped', 'deleted', 'failed', 'over_limit' ];

    public static function empty(): array {
        return array_fill_keys( self::KEYS, 0 );
    }

    /**
     * The per-batch result inside a sync API response. The platform wraps
     * every response as {"status": ..., "data": {...}} (BaseApiController),
     * so the counts live at data.result — reading a top-level 'result', as
     * every sync path here used to, always found nothing, and the admin
     * results table showed zeros for every sync ever run.
     */
    public static function result_of( $response ): array {
        if ( ! is_array( $response ) ) return [];
        $result = $response['data']['result'] ?? $response['result'] ?? [];
        return is_array( $result ) ? $result : [];
    }

    /** Adds one batch's platform-reported result onto the running totals. */
    public static function add( array &$totals, array $result ): void {
        foreach ( self::KEYS as $key ) {
            $totals[ $key ] += (int) ( $result[ $key ] ?? 0 );
        }
    }
}
