<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Product_Sync {
    // See Hamman_Page_Sync::MAX_BATCH_BYTES — batches sized by byte length, not
    // item count, to stay under the host's ~128KB WAF request-body limit.
    const MAX_BATCH_BYTES = 80000;
    const FETCH_PAGE_SIZE = 50;

    // "Is this genuine?" — the single most common question across both real
    // customer interviews this was built from (electronics parts AND
    // cosmetics). MUST be a data field the seller actually entered, never
    // something the model infers — see the admin-configured mapping this
    // reads from (Sync tab, hamman_field_mapping option) and
    // authenticity_fields() below. Shared with class-hamman-admin.php's
    // settings form so both sides list the exact same 5 fields.
    const AUTHENTICITY_FIELDS = [
        'authenticity_status', 'brand', 'official_distributor',
        'warranty_period', 'country_of_origin',
    ];

    public function __construct( private Hamman_Api_Client $api ) {}

    public function sync_all( string $cid ): array {
        if (!class_exists('WooCommerce')) return ['skipped'=>'WooCommerce not active'];
        $page=1; $synced=0; $errors=[]; $batch=[]; $batchBytes=0;
        do {
            $products = wc_get_products(['status'=>'publish','limit'=>self::FETCH_PAGE_SIZE,'page'=>$page,'return'=>'objects']);
            foreach ($products as $product) {
                $item     = $this->product_to_array($product);
                $itemSize = strlen(wp_json_encode($item));
                if (!empty($batch) && ($batchBytes + $itemSize) > self::MAX_BATCH_BYTES) {
                    $this->flush_batch($cid, $batch, $synced, $errors);
                    $batch = []; $batchBytes = 0;
                }
                $batch[]     = $item;
                $batchBytes += $itemSize;
            }
            $page++;
        } while (count($products) === self::FETCH_PAGE_SIZE);
        $this->flush_batch($cid, $batch, $synced, $errors);
        $result = ['synced'=>$synced];
        if (!empty($errors)) $result['errors'] = array_values(array_unique($errors));
        return $result;
    }

    private function flush_batch( string $cid, array $batch, int &$synced, array &$errors ): void {
        if (empty($batch)) return;
        $r = $this->api->sync_products($cid, $batch);
        if (is_wp_error($r)) { $errors[] = $r->get_error_message(); }
        else { $synced += count($batch); }
    }

    public function sync_recent( string $cid, int $hours=25 ): void {
        if (!class_exists('WooCommerce')) return;
        $products = wc_get_products(['status'=>'publish','limit'=>self::FETCH_PAGE_SIZE,'date_modified'=>'>'.date('Y-m-d H:i:s',strtotime("-{$hours} hours"))]);
        if (empty($products)) return;
        $synced = 0; $errors = [];
        $this->flush_batch($cid, array_map([$this,'product_to_array'],$products), $synced, $errors);
    }

    public function product_to_array( \WC_Product $p ): array {
        $cats = [];
        foreach ($p->get_category_ids() as $cid) {
            $term = get_term($cid,'product_cat');
            if ($term && !is_wp_error($term)) $cats[] = ['id'=>$term->term_id,'name'=>$term->name];
        }
        $base = [
            'id'=>$p->get_id(),'name'=>$p->get_name(),'slug'=>$p->get_slug(),'sku'=>$p->get_sku(),
            'type'=>$p->get_type(),'status'=>$p->get_status(),
            'description'=>wp_strip_all_tags($p->get_description()),
            'short_description'=>wp_strip_all_tags($p->get_short_description()),
            'price'=>(float)$p->get_price(),'regular_price'=>(float)$p->get_regular_price(),
            'sale_price'=>(float)$p->get_sale_price(),'currency'=>get_woocommerce_currency(),
            'stock_status'=>$p->get_stock_status(),'stock_quantity'=>$p->get_stock_quantity(),
            'average_rating'=>(float)$p->get_average_rating(),'review_count'=>$p->get_review_count(),
            'permalink' => get_site_url() . '/?p=' . $p->get_id(),
            'featured_image'=>get_the_post_thumbnail_url($p->get_id(),'medium')?:null,
            'categories'=>$cats,
            'tags'=>wp_get_post_terms($p->get_id(),'product_tag',['fields'=>'names']),
            'attachments'=>$this->product_pdf_attachments($p),
        ];
        return array_merge( $base, $this->authenticity_fields( $p ) );
    }

    /**
     * "Is this genuine?" — real, seller-entered data only, never something
     * the model infers from a description or reviews (see rag_service.
     * _authenticity_rule() on the Python side, which enforces this at
     * answer time too). Every store names its custom fields differently, so
     * this reads from the admin-configured mapping (Sync tab,
     * hamman_field_mapping option: field => {type: 'meta'|'attribute', key})
     * rather than guessing a field name — a semantic field with no mapping,
     * or whose mapped source is empty for this specific product, comes back
     * null, never a guess pulled from an unrelated field.
     */
    private function authenticity_fields( \WC_Product $p ): array {
        $mapping = get_option( 'hamman_field_mapping', [] );
        if ( ! is_array( $mapping ) ) $mapping = [];

        $out = [];
        foreach ( self::AUTHENTICITY_FIELDS as $field ) {
            $out[ $field ] = $this->read_mapped_field( $p, is_array( $mapping[ $field ] ?? null ) ? $mapping[ $field ] : null );
        }
        return $out;
    }

    private function read_mapped_field( \WC_Product $p, ?array $conf ): ?string {
        if ( ! $conf || empty( $conf['key'] ) ) return null;
        $key  = (string) $conf['key'];
        $type = $conf['type'] ?? '';

        if ( 'meta' === $type ) {
            $value = get_post_meta( $p->get_id(), $key, true );
            $value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
        } elseif ( 'attribute' === $type ) {
            $value = $this->read_attribute_value( $p, $key );
        } else {
            return null;
        }

        $value = trim( wp_strip_all_tags( $value ) );
        return '' !== $value ? $value : null;
    }

    private function read_attribute_value( \WC_Product $p, string $attribute_name ): string {
        $attributes = $p->get_attributes();
        if ( ! isset( $attributes[ $attribute_name ] ) ) return '';
        $attribute = $attributes[ $attribute_name ];
        if ( $attribute->is_taxonomy() ) {
            $terms = wc_get_product_terms( $p->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
            return implode( ', ', $terms );
        }
        return implode( ', ', $attribute->get_options() );
    }

    /**
     * The shop's most expensive question type, per the interview this
     * traces back to, comes from a datasheet — but a datasheet is rarely
     * "the product description," it's a PDF sitting either as a WordPress
     * media attachment on the product post, or just linked from inside the
     * description HTML. wp_strip_all_tags() (used above for the text
     * fields) already discarded those href attributes, so this scans the
     * *raw* description/short_description before that stripping happens.
     * Deduplicated by URL — the same file linked in text and also attached
     * as media must only be synced once.
     */
    private function product_pdf_attachments( \WC_Product $p ): array {
        if ( get_option( 'hamman_sync_pdfs', '1' ) !== '1' ) return [];

        $found = []; // url => name

        foreach ( get_attached_media( 'application/pdf', $p->get_id() ) as $attachment ) {
            $url = wp_get_attachment_url( $attachment->ID );
            if ( $url ) $found[ $url ] = $attachment->post_title ?: basename( $url );
        }

        $raw_html = $p->get_description() . ' ' . $p->get_short_description();
        if ( preg_match_all( '/href=["\']([^"\']+\.pdf)(?:[?#][^"\']*)?["\']/i', $raw_html, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                if ( ! isset( $found[ $url ] ) ) $found[ $url ] = basename( parse_url( $url, PHP_URL_PATH ) ?: $url );
            }
        }

        $attachments = [];
        foreach ( $found as $url => $name ) {
            $attachments[] = [ 'url' => $url, 'name' => $name ];
        }
        return $attachments;
    }
}
