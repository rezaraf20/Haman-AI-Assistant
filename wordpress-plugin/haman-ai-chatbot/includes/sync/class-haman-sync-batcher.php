<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Groups items into sync requests that respect BOTH limits: the host's
 * request-body size (~128KB WAF limit, so 80KB of JSON) and the platform's
 * item count (Haman_Sync_Limits). A batch is sent as soon as adding the
 * next item would break either one. One place for products, pages and
 * FAQs, full and incremental, so they cannot drift apart again.
 */
class Haman_Sync_Batcher {
    const MAX_BATCH_BYTES = 80000;

    /**
     * How request bodies are encoded — here and in Haman_Api_Client, so the
     * size measured is the size sent. Unescaped Unicode: escaped, every
     * Persian character became a 6-byte "م" instead of 2 bytes, which
     * tripled request sizes (a 30KB description was a 140KB request, over
     * the host's 128KB firewall limit on its own).
     */
    const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private array $batch = [];
    private int $bytes = 0;
    private int $max_items;

    /** @param callable(array):void $send receives each batch */
    public function __construct( private $send, ?int $max_items = null ) {
        $this->max_items = max( 1, $max_items ?? Haman_Sync_Limits::items_per_request() );
    }

    public function add( array $item ): void {
        $size = strlen( (string) wp_json_encode( $item, self::JSON_FLAGS ) );
        if ( ! empty( $this->batch ) && ( count( $this->batch ) >= $this->max_items || $this->bytes + $size > self::MAX_BATCH_BYTES ) ) {
            $this->flush();
        }
        $this->batch[] = $item;
        $this->bytes  += $size;
    }

    public function flush(): void {
        if ( empty( $this->batch ) ) return;
        ( $this->send )( $this->batch );
        $this->batch = [];
        $this->bytes = 0;
    }

    /** Convenience for a list already in hand (incremental syncs). */
    public static function send_all( array $items, callable $send ): void {
        $b = new self( $send );
        foreach ( $items as $item ) $b->add( $item );
        $b->flush();
    }
}
