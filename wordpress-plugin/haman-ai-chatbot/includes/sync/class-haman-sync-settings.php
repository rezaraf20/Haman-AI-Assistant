<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * What this site syncs, beyond WooCommerce products: which post types
 * (page, post, and any custom type — a "portfolio", "service", "project"…)
 * and, per type, which custom fields the site owner approved for sending.
 *
 * Stored locally in two options, and kept in step with the platform, which
 * is authoritative (App\Support\SyncSettings, version 2):
 *   - pull_from_server() runs before every full sync and when the settings
 *     page renders, so a change made in the customer portal takes effect
 *     here without anyone touching WordPress;
 *   - server_payload() is what a settings save pushes back up, including
 *     the list of post types this site actually has (the portal cannot see
 *     them otherwise).
 *
 * Custom fields are opt-in per key, from a preview of the keys the site
 * really has (discover_meta_keys()), never "all meta": a post type can
 * carry internal notes, wholesale prices or customer details in its meta.
 * Keys starting with "_" — WordPress's own convention for private meta —
 * are never offered and never sent, and the platform refuses them too.
 */
class Haman_Sync_Settings {
    const POST_TYPES_OPTION    = 'haman_sync_post_types';
    const CUSTOM_FIELDS_OPTION = 'haman_sync_custom_fields';
    const UNPUSHED_OPTION      = 'haman_sync_settings_unpushed';

    /** Never offered: not content, or (product) synced on its own path. */
    const NON_CONTENT_TYPES = [ 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles',
        'wp_navigation', 'wp_font_family', 'wp_font_face', 'elementor_library', 'e-landing-page' ];

    /**
     * Post types selected for syncing. Before 2.6.0 there were only two
     * flags (haman_sync_pages, haman_sync_posts); a site upgrading keeps
     * exactly that choice until it saves the new list.
     */
    public static function enabled_post_types(): array {
        $stored = get_option( self::POST_TYPES_OPTION, null );
        if ( is_array( $stored ) ) {
            return array_values( array_filter( array_map( 'sanitize_key', $stored ), [ __CLASS__, 'is_syncable_type' ] ) );
        }
        $types = [];
        if ( get_option( 'haman_sync_pages', '1' ) === '1' ) $types[] = 'page';
        if ( get_option( 'haman_sync_posts', '0' ) === '1' ) $types[] = 'post';
        return $types;
    }

    public static function set_enabled_post_types( array $types ): void {
        $types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ), [ __CLASS__, 'is_syncable_type' ] ) ) );
        update_option( self::POST_TYPES_OPTION, $types );
        // Kept for anything still reading the version-1 flags.
        update_option( 'haman_sync_pages', in_array( 'page', $types, true ) ? '1' : '0' );
        update_option( 'haman_sync_posts', in_array( 'post', $types, true ) ? '1' : '0' );
    }

    public static function is_syncable_type( string $type ): bool {
        return $type !== '' && $type !== 'product' && ! in_array( $type, self::NON_CONTENT_TYPES, true );
    }

    /**
     * Every public post type this site has, with its own label and how many
     * published items it holds — read from WordPress, never a hand-written
     * list, so a theme's or plugin's custom type shows up by itself.
     * 'product' is included but flagged, since it has its own sync.
     *
     * @return array<string, array{label: string, count: int, separate: bool}>
     */
    public static function available_post_types(): array {
        $out = [];
        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $slug => $obj ) {
            if ( in_array( $slug, self::NON_CONTENT_TYPES, true ) ) continue;
            $counts = wp_count_posts( $slug );
            $out[ $slug ] = [
                'label'    => (string) ( $obj->labels->name ?? $obj->label ?? $slug ),
                'count'    => (int) ( $counts->publish ?? 0 ),
                'separate' => $slug === 'product',
            ];
        }
        return $out;
    }

    /** @return array<string, list<string>> post type => approved meta keys */
    public static function custom_fields(): array {
        $stored = get_option( self::CUSTOM_FIELDS_OPTION, [] );
        return is_array( $stored ) ? self::clean_custom_fields( $stored ) : [];
    }

    public static function approved_fields_for( string $post_type ): array {
        return self::custom_fields()[ $post_type ] ?? [];
    }

    public static function set_custom_fields( array $map ): void {
        update_option( self::CUSTOM_FIELDS_OPTION, self::clean_custom_fields( $map ) );
    }

    private static function clean_custom_fields( array $map ): array {
        $out = [];
        foreach ( $map as $type => $keys ) {
            $type = sanitize_key( (string) $type );
            if ( $type === '' || ! is_array( $keys ) ) continue;
            $clean = [];
            foreach ( $keys as $key ) {
                $key = trim( wp_unslash( (string) $key ) );
                if ( $key === '' || $key[0] === '_' || strlen( $key ) > 191 ) continue;
                $clean[] = $key;
            }
            if ( $clean ) $out[ $type ] = array_values( array_unique( $clean ) );
        }
        return $out;
    }

    /**
     * The custom-field preview: every non-private meta key the published
     * items of one post type actually carry, how many items have it, and a
     * real sample value — so the owner sees exactly what would be sent
     * before approving it. Serialized (array/object) values are reported as
     * not sendable rather than offered: they are not readable text.
     *
     * @return list<array{key: string, items: int, sample: string, sendable: bool}>
     */
    public static function discover_meta_keys( string $post_type, int $limit = 40 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_key AS meta_key, COUNT(DISTINCT pm.post_id) AS items, MAX(LEFT(pm.meta_value, 160)) AS sample
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_key NOT LIKE %s
               AND pm.meta_value <> ''
             GROUP BY pm.meta_key
             ORDER BY items DESC, pm.meta_key ASC
             LIMIT %d",
            $post_type, $wpdb->esc_like( '_' ) . '%', $limit
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $sample = (string) $row->sample;
            $out[] = [
                'key'      => (string) $row->meta_key,
                'items'    => (int) $row->items,
                'sample'   => is_serialized( $sample ) ? '' : wp_strip_all_tags( $sample ),
                'sendable' => ! is_serialized( $sample ),
            ];
        }
        return $out;
    }

    /** What a settings save pushes to PUT /chatbots/{id}/sync-settings. */
    public static function server_payload(): array {
        $available = [];
        foreach ( self::available_post_types() as $slug => $info ) {
            if ( $info['separate'] ) continue;
            $available[ $slug ] = [ 'label' => $info['label'], 'count' => $info['count'] ];
        }
        return [
            'sync_products'         => get_option( 'haman_sync_products', '1' ) === '1',
            'post_types'            => self::enabled_post_types(),
            // An empty map would be sent as a JSON array; an object keeps the
            // platform's "post type => keys" reading unambiguous.
            'custom_fields'         => (object) self::custom_fields(),
            'available_post_types'  => (object) $available,
            'excluded_category_ids' => self::id_list( get_option( 'haman_sync_excluded_category_ids', '' ) ),
            'excluded_page_ids'     => self::id_list( get_option( 'haman_sync_excluded_page_ids', '' ) ),
        ];
    }

    /**
     * The platform is authoritative: whatever was last saved there (from
     * either side) replaces the local copy. A failure leaves local settings
     * as they were — never blocks a sync or the settings page.
     */
    public static function pull_from_server(): bool {
        $chatbot_id = get_option( 'haman_chatbot_id', '' );
        if ( empty( $chatbot_id ) || empty( get_option( 'haman_api_key' ) ) ) return false;

        // A local change that never reached the platform (the push failed)
        // must not be overwritten by the platform's older copy: push it
        // first, and only pull once the platform actually has it.
        if ( get_option( self::UNPUSHED_OPTION ) ) {
            if ( ! self::push_to_server() ) return false;
        }

        $r = ( new Haman_Api_Client() )->get_sync_settings( $chatbot_id );
        if ( is_wp_error( $r ) || ! is_array( $r['data'] ?? null ) ) return false;
        $s = $r['data'];
        Haman_Sync_Limits::remember( $s );

        if ( isset( $s['post_types'] ) && is_array( $s['post_types'] ) ) self::set_enabled_post_types( $s['post_types'] );
        if ( isset( $s['custom_fields'] ) && is_array( $s['custom_fields'] ) ) self::set_custom_fields( $s['custom_fields'] );
        if ( array_key_exists( 'sync_products', $s ) ) update_option( 'haman_sync_products', $s['sync_products'] ? '1' : '0' );
        if ( isset( $s['excluded_category_ids'] ) && is_array( $s['excluded_category_ids'] ) ) {
            update_option( 'haman_sync_excluded_category_ids', implode( ', ', array_map( 'intval', $s['excluded_category_ids'] ) ) );
        }
        if ( isset( $s['excluded_page_ids'] ) && is_array( $s['excluded_page_ids'] ) ) {
            update_option( 'haman_sync_excluded_page_ids', implode( ', ', array_map( 'intval', $s['excluded_page_ids'] ) ) );
        }
        return true;
    }

    /**
     * Sends server_payload() up. On failure the change is flagged as not yet
     * pushed (see pull_from_server()) instead of being silently lost.
     *
     * @return array|false the platform's response data, or false
     */
    public static function push_to_server() {
        $chatbot_id = get_option( 'haman_chatbot_id', '' );
        if ( empty( $chatbot_id ) ) return false;
        $r = ( new Haman_Api_Client() )->update_sync_settings( $chatbot_id, self::server_payload() );
        if ( is_wp_error( $r ) ) {
            update_option( self::UNPUSHED_OPTION, 1 );
            Haman_Admin::log( 'Push sync settings to server failed: ' . $r->get_error_message() );
            return false;
        }
        delete_option( self::UNPUSHED_OPTION );
        return is_array( $r['data'] ?? null ) ? $r['data'] : [];
    }

    private static function id_list( string $raw ): array {
        if ( '' === trim( $raw ) ) return [];
        return array_values( array_filter( array_map( 'intval', array_map( 'trim', explode( ',', $raw ) ) ) ) );
    }
}
