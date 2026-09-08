<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reverse-direction endpoint: the AI service's tool-calling path (see
 * check_product_availability in python-ai-service/app/services/tools/)
 * calls INTO this site to read LIVE WooCommerce data — stock/price
 * straight from this site's own database, not the last-synced snapshot
 * the regular indexed content is built from. Authenticated with the same
 * HMAC scheme (X-Hamman-Signature, sha256=hex(hmac_sha256(body,
 * webhook_secret))) already used for this plugin's existing outbound sync
 * webhooks — no new credential for the customer to manage, and this site
 * already trusts that secret.
 *
 * A short, dedicated rate limit protects this specific site's database
 * from being hammered — separate from any other limiter in this plugin,
 * since this is the one endpoint that runs a real live DB query per call.
 */
class Hamman_Live_Query_Handler {
    const RATE_LIMIT_MAX_PER_MINUTE = 20;

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
            case 'check_stock':
                return $this->check_stock( $body );
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
     * SECURITY: $sku is checked against a strict allowlist regex before it
     * touches anything else — never trusted, never concatenated into raw
     * SQL or a shell context. wc_get_product_id_by_sku()/wc_get_product()
     * go through WooCommerce's own parameterized data store, not raw SQL
     * built in this plugin.
     */
    private function check_stock( array $body ): WP_REST_Response {
        $sku = isset( $body['sku'] ) ? (string) $body['sku'] : '';
        if ( '' === $sku || ! preg_match( '/^[A-Za-z0-9\-_. ]{1,64}$/', $sku ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid sku' ], 400 );
        }

        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            return new WP_REST_Response( [ 'found' => false ], 200 );
        }
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_REST_Response( [ 'found' => false ], 200 );
        }

        return new WP_REST_Response( [
            'found'        => true,
            'name'         => $product->get_name(),
            'sku'          => $product->get_sku(),
            'price'        => (float) $product->get_price(),
            'currency'     => get_woocommerce_currency(),
            'stock_status' => $product->get_stock_status(),
        ], 200 );
    }
}
