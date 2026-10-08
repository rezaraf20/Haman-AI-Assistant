<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Haman_Api_Client {
    private string $api_key;
    private string $base_url;

    public function __construct() {
        $this->api_key  = get_option( 'haman_api_key', '' );
        $this->base_url = rtrim( get_option( 'haman_api_url', HAMAN_API_BASE ), '/' );
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
    // Real-time product/page update notifications need to sign with the
    // *actual* current secret — the secret itself is no longer stored
    // locally at all (see get_webhook_secret()/regenerate_webhook_secret()
    // — it lives only on the server now, fetched on demand for the
    // Connection tab's read-only display). A short transient cache avoids
    // one extra HTTP round-trip per webhook while still picking up a
    // regenerated secret within an hour rather than staying stale forever.
    const WEBHOOK_SECRET_CACHE_TTL = HOUR_IN_SECONDS;

    public function send_webhook( array $payload ): array|WP_Error {
        $secret = get_transient( 'haman_webhook_secret_cache' );
        if ( false === $secret ) {
            $secret = $this->get_webhook_secret();
            if ( is_wp_error( $secret ) ) return $secret;
            set_transient( 'haman_webhook_secret_cache', $secret, self::WEBHOOK_SECRET_CACHE_TTL );
        }
        $body      = wp_json_encode( $payload );
        $signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
        return $this->request( 'POST', '/sync/webhook', $payload, [ 'X-Haman-Signature' => $signature ] );
    }
    // Kept for any other internal caller wanting a plain bool — the
    // Connection tab's own "Test Connection" button uses test_connection()
    // instead, since the server logs that call and can report a precise
    // failure reason rather than just true/false.
    public function verify_connection(): bool {
        $r = $this->request( 'GET', '/chatbots' );
        return ! is_wp_error( $r );
    }
    /**
     * "Test Connection", v2 — hits a dedicated server endpoint
     * (ConnectionTestController) that logs every attempt, success or
     * failure, on the server side (api_keys.last_used_at alone only ever
     * told us THAT a plugin never connected, never whether anyone had
     * tried). Returns a precise, bilingual reason a merchant can actually
     * act on instead of a bare "failed" — see $reason_messages below for
     * the exact set the server can report.
     *
     * A request that never reaches the server at all (wrong base URL,
     * DNS failure, the site genuinely unreachable) shows up here as a
     * WP_Error from wp_remote_request() itself — that case is the one
     * this method can diagnose that the server never could, since the
     * server never saw the request to log it.
     *
     * @return array{ok:true,message:string}|WP_Error
     */
    public function test_connection(): array|WP_Error {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', 'کلید API وارد نشده / No API key entered' );
        }

        $response = wp_remote_request( $this->base_url . '/connection-test', [
            'method'  => 'POST',
            'headers' => [ 'Authorization' => 'Bearer ' . $this->api_key, 'Accept' => 'application/json' ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            // The request never reached the server at all -- this is the
            // ONE case the server-side log can never cover, by definition.
            return new WP_Error(
                'network',
                'اتصال به سرور برقرار نشد (مشکل شبکه یا آدرس سرور اشتباه است): ' . $response->get_error_message()
                . ' / Could not reach the server (network problem or wrong server address): ' . $response->get_error_message()
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $reason = $body['reason'] ?? null;

        if ( ( $body['ok'] ?? false ) === true ) {
            $name = $body['chatbot_name'] ?? '';
            return [ 'ok' => true, 'message' => $name
                ? "متصل شد به «{$name}» / Connected as \"{$name}\""
                : 'اتصال موفق / Connected successfully' ];
        }

        $reason_messages = [
            'missing_key'       => 'کلید API ارسال نشد / No API key was sent',
            'invalid_key'       => 'کلید API نامعتبر است یا دوباره ساخته شده — کلید جدید را از پورتال کپی کنید / The API key is invalid or was regenerated — copy the current one from the portal',
            'account_error'     => 'خطا در حساب تننت مرتبط با این کلید / An error with the tenant account behind this key',
            'key_expired'       => 'کلید API منقضی شده است / This API key has expired',
            'chatbot_suspended' => 'این چت‌بات غیرفعال یا معلق است — وضعیت پلن/آزمایشی را در پورتال بررسی کنید / This chatbot is suspended — check your plan/trial status in the portal',
        ];
        $message = $reason_messages[ $reason ] ?? ( $body['message'] ?? 'خطای نامشخص / Unknown error' );

        return new WP_Error( $reason ?? 'unknown', $message );
    }

    /** No longer used by the Connection tab (see test_connection()); kept
     * for any other internal caller that wants the connected chatbot's
     * real name specifically, proving the configured chatbot_id resolves.
     * @return string|WP_Error the connected chatbot's name, or the error the admin should see verbatim. */
    public function get_connected_chatbot_name(): string|WP_Error {
        $chatbot_id = get_option( 'haman_chatbot_id', '' );
        if ( empty( $chatbot_id ) ) {
            return new WP_Error( 'no_chatbot_id', 'شناسه‌ی چت‌بات تنظیم نشده / No Chatbot ID configured' );
        }
        $r = $this->request( 'GET', "/chatbots/{$chatbot_id}" );
        if ( is_wp_error( $r ) ) return $r;
        return $r['data']['name'] ?? '(بدون نام / unnamed)';
    }
    public function update_widget_settings( string $chatbot_id, array $settings ): array|WP_Error {
        return $this->request( 'PUT', "/chatbots/{$chatbot_id}/widget-settings", $settings );
    }
    /** @return array|WP_Error The complete, always-defaulted config (see
     * App\Support\WidgetDefaults::merge() on the server) for the read-only
     * "ظاهر و متن‌ها" tab display. */
    public function get_widget_settings_for_display( string $chatbot_id ): array|WP_Error {
        $r = $this->request( 'GET', "/chatbots/{$chatbot_id}" );
        if ( is_wp_error( $r ) ) return $r;
        $config = $r['data']['widget_config_merged'] ?? [];
        return [
            'welcome_message'    => $config['welcome_message'] ?? '',
            'chat_title'         => $config['chat_title'] ?? '',
            'ai_name'            => $config['ai_name'] ?? '',
            'avatar_url'         => $config['avatar_url'] ?? '',
            'primary_color'      => $config['primary_color'] ?? '',
            'position'           => $config['position'] ?? '',
            'system_instruction' => $config['system_instruction'] ?? '',
            'quick_questions'    => $config['quick_questions'] ?? [],
            'lead_capture_prompt'  => $config['lead_capture_prompt'] ?? '',
            'lead_capture_thanks'  => $config['lead_capture_thanks'] ?? '',
            'lead_capture_invalid' => $config['lead_capture_invalid'] ?? '',
            'lead_capture_enabled' => $config['lead_capture_enabled'] ?? false,
        ];
    }
    /** @return array|WP_Error the always-defaulted sync scope (see
     * App\Support\SyncSettings::merge() on the server) — the customer
     * portal's SyncSettings page is authoritative; this is how a change
     * made there reaches this site without an admin having to also click
     * Save here. */
    public function get_sync_settings( string $chatbot_id ): array|WP_Error {
        return $this->request( 'GET', "/chatbots/{$chatbot_id}/sync-settings" );
    }
    public function update_sync_settings( string $chatbot_id, array $settings ): array|WP_Error {
        return $this->request( 'PUT', "/chatbots/{$chatbot_id}/sync-settings", $settings );
    }
    /** "Clear and reindex" — wipes this chatbot's synced documents server-side
     * (never manually-entered FAQs). The caller is responsible for
     * triggering a normal full sync right after; this alone only clears. */
    public function clear_index( string $chatbot_id ): array|WP_Error {
        return $this->request( 'POST', '/sync/clear', [ 'chatbot_id' => $chatbot_id ], [], self::BULK_SYNC_TIMEOUT );
    }
    public function get_webhook_secret(): string|WP_Error {
        $r = $this->request( 'GET', '/tenant/webhook-secret' );
        if ( is_wp_error( $r ) ) return $r;
        return $r['data']['webhook_secret'] ?? '';
    }
    public function regenerate_webhook_secret(): string|WP_Error {
        $r = $this->request( 'POST', '/tenant/webhook-secret/regenerate' );
        if ( is_wp_error( $r ) ) return $r;
        return $r['data']['webhook_secret'] ?? '';
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
        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'Haman_Admin' ) ) Haman_Admin::log( "{$method} {$path} failed: " . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 400 ) {
            $msg = $body['error'] ?? "HTTP {$code}";
            if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
                $msg .= ': ' . wp_json_encode( $body['errors'] );
            }
            if ( class_exists( 'Haman_Admin' ) ) Haman_Admin::log( "{$method} {$path} failed: {$msg}" );
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
