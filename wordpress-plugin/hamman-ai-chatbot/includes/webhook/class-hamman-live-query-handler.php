<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reverse-direction endpoint: the AI service's tool-calling path (see
 * python-ai-service/app/services/tools/product_tools.py) calls INTO this
 * site to read LIVE WooCommerce data — stock/price/variants/search straight
 * from this site's own database, not the last-synced snapshot the regular
 * indexed content is built from. Authenticated with the same HMAC scheme
 * (X-Hamman-Signature, sha256=hex(hmac_sha256(body, webhook_secret)))
 * already used for this plugin's existing outbound sync webhooks — no new
 * credential for the customer to manage, and this site already trusts that
 * secret.
 *
 * A short, dedicated rate limit protects this specific site's database
 * from being hammered — separate from any other limiter in this plugin,
 * since this is the one endpoint that runs real live DB/product queries
 * per call.
 */
class Hamman_Live_Query_Handler {
    const RATE_LIMIT_MAX_PER_MINUTE = 20;
    const MAX_SEARCH_RESULTS        = 10;
    const MAX_VARIANTS_RETURNED     = 50;

    public function register_routes(): void {
        register_rest_route( 'hamman/v1', '/live-query', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle' ],
            // Auth is HMAC-based below, not WP's native REST permission
            // system — same posture as class-hamman-webhook-handler.php.
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->verify_signature( $req ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 403 );
        }
        if ( ! $this->check_rate_limit() ) {
            return new WP_REST_Response( [ 'error' => 'Rate limit exceeded' ], 429 );
        }
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce not active' ], 503 );
        }

        $body   = $req->get_json_params() ?: [];
        $action = $body['action'] ?? '';

        switch ( $action ) {
            case 'get_availability':
                return $this->get_availability( $body );
            case 'get_variants':
                return $this->get_variants( $body );
            case 'search_products':
                return $this->search_products( $body );
            default:
                return new WP_REST_Response( [ 'error' => 'Unknown action' ], 400 );
        }
    }

    private function verify_signature( WP_REST_Request $req ): bool {
        $sig = $req->get_header( 'X-Hamman-Signature' );
        if ( empty( $sig ) ) return false;
        $secret = $this->get_secret();
        if ( empty( $secret ) ) return false;
        $expected = 'sha256=' . hash_hmac( 'sha256', $req->get_body(), $secret );
        return hash_equals( $expected, (string) $sig );
    }

    /** Same cached-from-server secret Hamman_Api_Client::send_webhook()
     * already uses for the outbound direction — never stored as a
     * permanent local option (see class-hamman-admin.php's Connection tab
     * docblock), just cached an hour at a time. */
    private function get_secret(): string {
        $cached = get_transient( 'hamman_webhook_secret_cache' );
        if ( false !== $cached ) return (string) $cached;
        $secret = ( new Hamman_Api_Client() )->get_webhook_secret();
        if ( is_wp_error( $secret ) ) return '';
        set_transient( 'hamman_webhook_secret_cache', $secret, HOUR_IN_SECONDS );
        return $secret;
    }

    private function check_rate_limit(): bool {
        $key   = 'hamman_toolcall_rl_' . gmdate( 'YmdHi' ); // fixed 1-minute bucket
        $count = (int) get_transient( $key );
        if ( $count >= self::RATE_LIMIT_MAX_PER_MINUTE ) return false;
        set_transient( $key, $count + 1, 70 );
        return true;
    }

    /**
     * SECURITY: $sku is checked against a strict allowlist regex and
     * $product_id is always cast with absint() before either touches
     * anything else — never trusted, never concatenated into raw SQL or a
     * shell context. Every WooCommerce call below goes through WC's own
     * parameterized data store / WP_Query, not raw SQL built in this
     * plugin.
     */
    private function resolve_product( array $body ) {
        if ( ! empty( $body['product_id'] ) ) {
            $id = absint( $body['product_id'] );
            if ( ! $id ) return new WP_Error( 'invalid_product_id', 'Invalid product_id' );
            return wc_get_product( $id ) ?: null;
        }
        if ( ! empty( $body['sku'] ) ) {
            $sku = (string) $body['sku'];
            if ( ! preg_match( '/^[A-Za-z0-9\-_. ]{1,64}$/', $sku ) ) {
                return new WP_Error( 'invalid_sku', 'Invalid sku' );
            }
            $id = wc_get_product_id_by_sku( $sku );
            return $id ? wc_get_product( $id ) : null;
        }
        return new WP_Error( 'missing_identifier', 'sku or product_id required' );
    }

    /** get_product_availability: stock, current price, sale price, publish
     * status — by SKU or numeric product_id, whichever the model supplied. */
    private function get_availability( array $body ): WP_REST_Response {
        $product = $this->resolve_product( $body );
        if ( is_wp_error( $product ) ) {
            return new WP_REST_Response( [ 'error' => $product->get_error_message() ], 400 );
        }
        if ( ! $product ) {
            return new WP_REST_Response( [ 'found' => false ], 200 );
        }

        return new WP_REST_Response( [
            'found'          => true,
            'product_id'     => $product->get_id(),
            'name'           => $product->get_name(),
            'sku'            => $product->get_sku(),
            // publish/draft/pending/private — lets the bot avoid describing
            // a product that isn't actually live on the site as available.
            'status'         => $product->get_status(),
            'stock_status'   => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'price'          => '' !== $product->get_price() ? (float) $product->get_price() : null,
            'regular_price'  => '' !== $product->get_regular_price() ? (float) $product->get_regular_price() : null,
            'sale_price'     => '' !== $product->get_sale_price() ? (float) $product->get_sale_price() : null,
            'on_sale'        => $product->is_on_sale(),
            'is_variable'    => $product->is_type( 'variable' ),
            'currency'       => get_woocommerce_currency(),
        ], 200 );
    }

    /** get_product_variants: every variation of a variable product (size,
     * color, or any other variable attribute), each with its OWN stock and
     * price — critical for a cosmetics shop (volume/shade) or parts store
     * (package size), where the parent product alone doesn't say enough. */
    private function get_variants( array $body ): WP_REST_Response {
        $id = absint( $body['product_id'] ?? 0 );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'error' => 'Invalid product_id' ], 400 );
        }
        $product = wc_get_product( $id );
        if ( ! $product ) {
            return new WP_REST_Response( [ 'found' => false ], 200 );
        }
        if ( ! $product->is_type( 'variable' ) ) {
            return new WP_REST_Response( [ 'found' => true, 'is_variable' => false, 'product_id' => $id, 'name' => $product->get_name() ], 200 );
        }

        $variants = [];
        foreach ( $product->get_children() as $variation_id ) {
            if ( count( $variants ) >= self::MAX_VARIANTS_RETURNED ) break;
            $variation = wc_get_product( $variation_id );
            if ( ! $variation ) continue;
            $variants[] = [
                'variation_id'   => $variation_id,
                // e.g. ['attribute_pa_size' => 'M', 'attribute_pa_color' => 'Red']
                'attributes'     => $variation->get_variation_attributes(),
                'sku'            => $variation->get_sku(),
                'stock_status'   => $variation->get_stock_status(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'price'          => '' !== $variation->get_price() ? (float) $variation->get_price() : null,
                'regular_price'  => '' !== $variation->get_regular_price() ? (float) $variation->get_regular_price() : null,
                'sale_price'     => '' !== $variation->get_sale_price() ? (float) $variation->get_sale_price() : null,
            ];
        }

        return new WP_REST_Response( [
            'found'       => true,
            'is_variable' => true,
            'product_id'  => $id,
            'name'        => $product->get_name(),
            'currency'    => get_woocommerce_currency(),
            'variants'    => $variants,
        ], 200 );
    }

    /**
     * search_products: STRUCTURED live catalog search — category, price
     * range, brand, in-stock-only — deliberately not a semantic/similarity
     * search (that's what the regular vector retrieval pipeline is for).
     * Every filter goes through wc_get_products()'s own WC_Product_Query
     * builder (WP_Query + parameterized meta_query/tax_query under the
     * hood), never raw SQL assembled in this plugin.
     */
    private function search_products( array $body ): WP_REST_Response {
        $args = [
            'status' => 'publish',
            'limit'  => min( self::MAX_SEARCH_RESULTS, max( 1, absint( $body['limit'] ?? 8 ) ) ),
            'return' => 'objects',
        ];

        if ( ! empty( $body['query'] ) ) {
            $q = sanitize_text_field( (string) $body['query'] );
            $args['s'] = mb_substr( $q, 0, 200 );
        }
        if ( ! empty( $body['category'] ) ) {
            $args['category'] = [ sanitize_title( (string) $body['category'] ) ];
        }
        if ( isset( $body['price_min'] ) && is_numeric( $body['price_min'] ) ) {
            $args['min_price'] = (float) $body['price_min'];
        }
        if ( isset( $body['price_max'] ) && is_numeric( $body['price_max'] ) ) {
            $args['max_price'] = (float) $body['price_max'];
        }
        if ( ! empty( $body['in_stock_only'] ) ) {
            $args['stock_status'] = 'instock';
        }
        if ( ! empty( $body['brand'] ) ) {
            $tax = $this->resolve_brand_taxonomy();
            // Silently ignored (not an error) if this store has no brand
            // taxonomy at all — same "unknown filter degrades gracefully"
            // posture as the rest of this integration, since not every
            // WooCommerce store has a brand plugin installed.
            if ( $tax ) {
                $args['tax_query'] = [ [
                    'taxonomy' => $tax,
                    'field'    => 'name',
                    'terms'    => sanitize_text_field( (string) $body['brand'] ),
                ] ];
            }
        }

        $products = wc_get_products( $args );
        $results  = [];
        foreach ( $products as $p ) {
            $results[] = [
                'product_id'   => $p->get_id(),
                'name'         => $p->get_name(),
                'sku'          => $p->get_sku(),
                'price'        => '' !== $p->get_price() ? (float) $p->get_price() : null,
                'on_sale'      => $p->is_on_sale(),
                'stock_status' => $p->get_stock_status(),
                'is_variable'  => $p->is_type( 'variable' ),
            ];
        }

        return new WP_REST_Response( [
            'count'    => count( $results ),
            'currency' => get_woocommerce_currency(),
            'results'  => $results,
        ], 200 );
    }

    /** Not every WooCommerce store has a brand taxonomy — there's no core
     * one. Checks the handful of real plugins that add one, in the order
     * they're actually seen in the wild, rather than assuming a specific
     * brand plugin is installed. */
    private function resolve_brand_taxonomy(): ?string {
        foreach ( [ 'product_brand', 'pwb-brand', 'pa_brand' ] as $tax ) {
            if ( taxonomy_exists( $tax ) ) return $tax;
        }
        return null;
    }
}
