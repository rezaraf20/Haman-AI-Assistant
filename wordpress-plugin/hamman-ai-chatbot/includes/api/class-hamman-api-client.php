<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Api_Client {
    private string $api_key;
    private string $base_url;

    public function __construct() {
        $this->api_key  = get_option( 'hamman_api_key', '' );
        $this->base_url = rtrim( get_option( 'hamman_api_url', HAMMAN_API_BASE ), '/' );
    }

    // Sync endpoints embed each item synchronously server-side (one external
    // embedding-API call per chunk), so a batch of pages/products can legitimately
    // take well past a normal HTTP timeout. Give these a generous timeout.
    const BULK_SYNC_TIMEOUT = 120;

    public function sync_products( string $chatbot_id, array $products ): array|WP_Error {
        return $this->request( 'POST', '/sync/products', [ 'chatbot_id' => $chatbot_id, 'products' => $products ], [], self::BULK_SYNC_TIMEOUT );
    }
    public function sync_pages( string $chatbot_id, array $pages ): array|WP_Error {
        return $this->request( 'POST', '/sync/pages', [ 'chatbot_id' => $chatbot_id, 'pages' => $pages ], [], self::BULK_SYNC_TIMEOUT );
    }
    public function sync_faqs( string $chatbot_id, array $faqs ): array|WP_Error {
        return $this->request( 'POST', '/sync/faqs', [ 'chatbot_id' => $chatbot_id, 'faqs' => $faqs ], [], self::BULK_SYNC_TIMEOUT );
    }
    public function send_webhook( array $payload ): array|WP_Error {
        $secret    = get_option( 'hamman_webhook_secret', '' );
        $body      = wp_json_encode( $payload );
        $signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
        return $this->request( 'POST', '/sync/webhook', $payload, [ 'X-Hamman-Signature' => $signature ] );
    }
    public function verify_connection(): bool {
        $r = $this->request( 'GET', '/chatbots' );
        return ! is_wp_error( $r );
    }
    public function update_widget_settings( string $chatbot_id, array $settings ): array|WP_Error {
        return $this->request( 'PUT', "/chatbots/{$chatbot_id}/widget-settings", $settings );
    }

    private function post( string $path, array $data ): array|WP_Error {
        return $this->request( 'POST', $path, $data );
    }

    private function request( string $method, string $path, array $data = [], array $extra = [], int $timeout = 30 ): array|WP_Error {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', 'API key not set' );
        }
        $headers = array_merge( [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ], $extra );

        $args = [ 'method' => $method, 'headers' => $headers, 'timeout' => $timeout ];
        if ( ! empty( $data ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ] ) ) {
            $encoded = wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE );
            if ( false === $encoded ) {
                // Retry once after stripping invalid UTF-8 byte sequences from every
                // string in the payload (e.g. garbled bytes picked up from Elementor
                // widget content) — json_encode() fails silently otherwise, which
                // previously caused an empty request body and confusing "field is
                // required" errors server-side instead of a clear local error.
                $sanitized = $this->sanitize_utf8( $data );
                $encoded   = wp_json_encode( $sanitized, JSON_INVALID_UTF8_SUBSTITUTE );
                if ( false === $encoded ) {
                    return new WP_Error( 'json_encode_failed', 'Failed to encode request body as JSON: ' . json_last_error_msg() );
                }
                $data = $sanitized;
            }
            $args['body'] = $encoded;
        }

        $response = wp_remote_request( $this->base_url . $path, $args );
        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 400 ) {
            $msg = $body['error'] ?? "HTTP {$code}";
            if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
                $msg .= ': ' . wp_json_encode( $body['errors'] );
            }
            return new WP_Error( "api_{$code}", $msg );
        }
        return $body ?? [];
    }

    private function sanitize_utf8( $value ) {
        if ( is_array( $value ) ) {
            return array_map( [ $this, 'sanitize_utf8' ], $value );
        }
        if ( is_string( $value ) ) {
            return mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
        }
        return $value;
    }
}
