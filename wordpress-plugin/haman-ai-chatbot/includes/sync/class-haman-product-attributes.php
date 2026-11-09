<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce product attributes (colour, size, material …), variations,
 * weight and dimensions — none of which reached the bot before 2.8.0, so
 * "do you have it in blue?" could only be answered when a description
 * happened to say so. khonehrangi.ir: a mug whose attribute lists
 * «قرمز | آبی | صورتی | زرد», and the bot told a customer it had no blue mug.
 *
 * Same shape as custom fields for post types: the Sync tab lists every
 * attribute this shop actually uses, with real sample values and how many
 * products carry it; the owner decides which the bot reads. Until they
 * decide, the default is what WooCommerce itself already shows customers
 * on the product page (the attribute's "Visible on the product page" box).
 * The choice is stored on the platform with the other sync settings
 * (product_attributes), so it can also be changed from the Haman panel.
 */
class Haman_Product_Attributes {
    const OPTION       = 'haman_sync_product_attributes';
    const MAX_VARIATIONS = 50;

    /** null = default (visible attributes), array = the owner's chosen keys. */
    public static function approved(): ?array {
        $v = get_option( self::OPTION, null );
        return is_array( $v ) ? array_values( array_filter( array_map( [ __CLASS__, 'clean_key' ], $v ) ) ) : null;
    }

    public static function set_approved( ?array $keys ): void {
        if ( $keys === null ) { delete_option( self::OPTION ); return; }
        update_option( self::OPTION, array_values( array_unique( array_filter( array_map( [ __CLASS__, 'clean_key' ], $keys ) ) ) ), false );
    }

    /** pa_color, or a custom attribute's sanitized name (WooCommerce may store it URL-encoded). */
    public static function clean_key( $key ): ?string {
        $key = trim( (string) $key );
        return ( $key !== '' && strlen( $key ) <= 200 && ! preg_match( '/[\s<>"\x27]/u', $key ) ) ? $key : null;
    }

    /**
     * Every attribute used by a published product on this site:
     * key => {label, count, visible, samples}. For the preview, and reported
     * to the platform so the Haman panel can show the same choice.
     */
    public static function available( int $limit = 2000 ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status = 'publish'
             WHERE pm.meta_key = '_product_attributes' AND pm.meta_value NOT IN ('', 'a:0:{}') LIMIT %d", $limit ) );

        $out = [];
        foreach ( $rows as $raw ) {
            $attrs = maybe_unserialize( $raw );
            if ( ! is_array( $attrs ) ) continue;
            foreach ( $attrs as $key => $a ) {
                $key = self::clean_key( $key );
                if ( $key === null || ! is_array( $a ) ) continue;
                $taxonomy = ! empty( $a['is_taxonomy'] );
                $out[ $key ] ??= [
                    'label'   => $taxonomy && function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $a['name'] ?? $key ) : (string) ( $a['name'] ?? urldecode( $key ) ),
                    'count'   => 0,
                    'visible' => false,
                    'samples' => [],
                    'taxonomy'=> $taxonomy,
                ];
                $out[ $key ]['count']++;
                if ( ! empty( $a['is_visible'] ) ) $out[ $key ]['visible'] = true;
                if ( ! $taxonomy && count( $out[ $key ]['samples'] ) < 6 ) {
                    foreach ( array_map( 'trim', explode( '|', (string) ( $a['value'] ?? '' ) ) ) as $v ) {
                        if ( $v !== '' && ! in_array( $v, $out[ $key ]['samples'], true ) && count( $out[ $key ]['samples'] ) < 6 ) $out[ $key ]['samples'][] = $v;
                    }
                }
            }
        }
        foreach ( $out as $key => &$info ) {
            if ( $info['taxonomy'] && taxonomy_exists( $key ) ) {
                $terms = get_terms( [ 'taxonomy' => $key, 'hide_empty' => false, 'number' => 6, 'fields' => 'names' ] );
                if ( is_array( $terms ) ) $info['samples'] = array_values( $terms );
            }
            unset( $info['taxonomy'] );
        }
        unset( $info );
        return $out;
    }

    /** This product's attributes the bot may read: [{name, label, values[]}]. */
    public static function for_product( \WC_Product $p ): array {
        $approved = self::approved();
        $out = [];
        foreach ( $p->get_attributes() as $key => $attr ) {
            if ( ! $attr instanceof \WC_Product_Attribute ) continue;
            $name = self::clean_key( $attr->get_name() );
            if ( $name === null ) continue;
            $allowed = $approved === null ? $attr->get_visible() : in_array( $name, $approved, true );
            if ( ! $allowed ) continue;
            $values = $attr->is_taxonomy()
                ? wc_get_product_terms( $p->get_id(), $attr->get_name(), [ 'fields' => 'names' ] )
                : $attr->get_options();
            $values = array_values( array_filter( array_map( fn ( $v ) => trim( wp_strip_all_tags( (string) $v ) ), (array) $values ) ) );
            if ( ! $values ) continue;
            $out[] = [ 'name' => $name, 'label' => wc_attribute_label( $attr->get_name(), $p ), 'values' => array_slice( $values, 0, 50 ) ];
        }
        return $out;
    }

    /** A variable product's options, each with its own price and stock. */
    public static function variations( \WC_Product $p ): array {
        if ( ! $p->is_type( 'variable' ) ) return [];
        $out = [];
        foreach ( array_slice( $p->get_children(), 0, self::MAX_VARIATIONS ) as $vid ) {
            $v = wc_get_product( $vid );
            if ( ! $v || $v->get_status() !== 'publish' ) continue;
            $attrs = [];
            foreach ( $v->get_variation_attributes() as $tax => $value ) {
                $tax = str_replace( 'attribute_', '', $tax );
                if ( $value === '' ) { $value = __( 'any', 'haman-ai-chatbot' ); }
                elseif ( taxonomy_exists( $tax ) ) { $term = get_term_by( 'slug', $value, $tax ); if ( $term ) $value = $term->name; }
                $attrs[ wc_attribute_label( $tax, $p ) ] = (string) $value;
            }
            $out[] = [ 'attributes' => $attrs, 'price' => $v->get_price(), 'stock_status' => $v->get_stock_status(), 'sku' => $v->get_sku() ];
        }
        return $out;
    }

    public static function weight( \WC_Product $p ): ?string {
        $w = $p->get_weight();
        return $w !== '' && $w !== null ? $w . ' ' . get_option( 'woocommerce_weight_unit', 'kg' ) : null;
    }

    public static function dimensions( \WC_Product $p ): ?string {
        if ( ! $p->has_dimensions() ) return null;
        return function_exists( 'wc_format_dimensions' ) ? wp_strip_all_tags( html_entity_decode( wc_format_dimensions( $p->get_dimensions( false ) ) ) ) : null;
    }
}
