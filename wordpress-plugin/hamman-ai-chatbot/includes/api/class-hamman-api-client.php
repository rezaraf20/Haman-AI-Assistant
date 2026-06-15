<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Api_Client {
    private string $api_key;
    private string $base_url;

    public function __construct() {
        $this->api_key  = get_option( 'hamman_api_key', '' );
        $this->base_url = rtrim( get_option( 'hamman_api_url', HAMMAN_API_BASE ), '/' );
    }

    public function sync_products( string $chatbot_id, array $products ): array|WP_Error {
        return $this->post( '/sync/products', [ 'chatbot_id' => $chatbot_id, 'products' => $products ] );
    }
    public function sync_pages( string $chatbot_id, array $pages ): array|WP_Error {
        return $this->post( '/sync/pages', [ 'chatbot_id' => $chatbot_id, 'pages' => $pages ] );
    }
    public function sync_faqs( string $chatbot_id, array $faqs ): array|WP_Error {
        return $this->post( '/sync/faqs', [ 'chatbot_id' => $chatbot_id, 'faqs' => $faqs ] );
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

    private function post( string $path, array $data ): array|WP_Error {
        return $this->request( 'POST', $path, $data );
    }

    private function request( string $method, string $path, array $data = [], array $extra = [] ): array|WP_Error {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', 'API key not set' );
        }
        $headers = array_merge( [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ], $extra );

        $args = [ 'method' => $method, 'headers' => $headers, 'timeout' => 30 ];
        if ( ! empty( $data ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ] ) ) {
            $args['body'] = wp_json_encode( $data );
        }

        $response = wp_remote_request( $this->base_url . $path, $args );
        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 400 ) return new WP_Error( "api_{$code}", $body['error'] ?? "HTTP {$code}" );
        return $body ?? [];
    }
}
