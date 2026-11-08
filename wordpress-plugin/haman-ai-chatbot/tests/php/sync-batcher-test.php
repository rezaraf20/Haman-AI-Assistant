<?php
/**
 * Haman_Sync_Batcher / Haman_Sync_Limits, without WordPress: the two
 * functions they use are stubbed below. Run: php tests/php/sync-batcher-test.php
 *
 * The bug this pins: batches were limited by size only, so 173 small
 * products went out ~100 to a request and the platform (50 items max)
 * refused every one but the last — 6 of 173 arrived. Both limits now hold,
 * and the item limit is whatever the platform last said.
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['options'] = [];
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
require __DIR__ . '/../../includes/sync/class-haman-sync-limits.php';
require __DIR__ . '/../../includes/sync/class-haman-sync-batcher.php';

$failures = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
    global $failures;
    echo ( $ok ? "ok   " : "FAIL " ) . $name . ( $ok || $detail === '' ? '' : " — {$detail}" ) . "\n";
    if ( ! $ok ) $failures++;
}
function run( array $items, ?int $max = null ): array {
    $sent = [];
    $b = new Haman_Sync_Batcher( function ( array $batch ) use ( &$sent ) { $sent[] = $batch; }, $max );
    foreach ( $items as $i ) $b->add( $i );
    $b->flush();
    return $sent;
}
$small = fn ( int $n ) => array_map( fn ( $i ) => [ 'id' => $i, 'name' => "ماگ {$i}", 'price' => '295000' ], range( 1, $n ) );
$big   = fn ( int $n, int $kb ) => array_map( fn ( $i ) => [ 'id' => $i, 'description' => str_repeat( 'x', $kb * 1024 ) ], range( 1, $n ) );

// 1. Many small items: the item count is what limits.
$sent = run( $small( 173 ) );
check( '173 small items, default limit 50 → 50/50/50/23', array_map( 'count', $sent ) === [ 50, 50, 50, 23 ], json_encode( array_map( 'count', $sent ) ) );
check( 'every item sent exactly once', array_sum( array_map( 'count', $sent ) ) === 173 );

// 2. Large items: the size is what limits, and stays under 80KB.
$sent = run( $big( 12, 30 ) );
$sizes = array_map( fn ( $b ) => strlen( wp_json_encode( $b, Haman_Sync_Batcher::JSON_FLAGS ) ), $sent );
check( '12 items of 30KB → no request over 80KB', max( $sizes ) <= Haman_Sync_Batcher::MAX_BATCH_BYTES, json_encode( $sizes ) );
check( '12 items of 30KB → 2 per request, all 12 sent', array_map( 'count', $sent ) === [ 2, 2, 2, 2, 2, 2 ] );

// 2b. Persian text is measured — and sent — unescaped: 2 bytes a letter, not 6.
$fa = array_map( fn ( $i ) => [ 'id' => $i, 'description' => str_repeat( 'ماگ سرامیکی ', 1300 ) ], range( 1, 6 ) ); // ~30KB each unescaped
$one = strlen( wp_json_encode( $fa[0], Haman_Sync_Batcher::JSON_FLAGS ) );
check( 'a 30KB Persian description measures ~30KB, not ~90KB escaped', $one < 35000 && strlen( json_encode( $fa[0] ) ) > 80000, "unescaped {$one}, escaped " . strlen( json_encode( $fa[0] ) ) );
$sent = run( $fa );
check( 'Persian items group 2 per request under 80KB (escaped, each would go alone)', array_map( 'count', $sent ) === [ 2, 2, 2 ], json_encode( array_map( 'count', $sent ) ) );

// 3. A single item bigger than the size limit still goes (alone) — never dropped.
$sent = run( $big( 3, 100 ) );
check( 'an oversized item is sent on its own, not lost', array_map( 'count', $sent ) === [ 1, 1, 1 ] );

// 4. Mixed: whichever limit comes first.
$mixed = array_merge( $small( 45 ), $big( 3, 30 ), $small( 60 ) );
$sent = run( $mixed );
$ok = true;
foreach ( $sent as $b ) { if ( count( $b ) > 50 ) $ok = false; if ( count( $b ) > 1 && strlen( wp_json_encode( $b, Haman_Sync_Batcher::JSON_FLAGS ) ) > 80000 ) $ok = false; }
check( 'mixed sizes: no batch breaks either limit', $ok );
check( 'mixed sizes: nothing lost', array_sum( array_map( 'count', $sent ) ) === 108 );

// 5. The item limit comes from the platform.
check( 'nothing stored yet → conservative 50', Haman_Sync_Limits::items_per_request() === 50 );
Haman_Sync_Limits::remember( [ 'ok' => true, 'limits' => [ 'sync_items_per_request' => 20 ] ] );
check( 'platform says 20 → 20', Haman_Sync_Limits::items_per_request() === 20 );
$sent = run( $small( 45 ) );
check( 'batches follow the stored limit (20/20/5)', array_map( 'count', $sent ) === [ 20, 20, 5 ], json_encode( array_map( 'count', $sent ) ) );
Haman_Sync_Limits::remember( [ 'limits' => [ 'sync_items_per_request' => 0 ] ] );
Haman_Sync_Limits::remember( [ 'limits' => [ 'sync_items_per_request' => 99999 ] ] );
Haman_Sync_Limits::remember( [ 'no_limits_here' => 1 ] );
check( 'nonsense values are ignored (keeps 20)', Haman_Sync_Limits::items_per_request() === 20 );
$GLOBALS['options']['haman_sync_items_per_request'] = 'abc';
check( 'a corrupted stored value falls back to 50', Haman_Sync_Limits::items_per_request() === 50 );

// 6. send_all (incremental syncs) respects the same limits.
$GLOBALS['options'] = [];
$sent = [];
Haman_Sync_Batcher::send_all( $small( 120 ), function ( array $b ) use ( &$sent ) { $sent[] = $b; } );
check( 'send_all: 120 items → 50/50/20', array_map( 'count', $sent ) === [ 50, 50, 20 ] );

echo $failures ? "\n{$failures} failed\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
