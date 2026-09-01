<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Product_Sync {
    // See Hamman_Page_Sync::MAX_BATCH_BYTES — batches sized by byte length, not
    // item count, to stay under the host's ~128KB WAF request-body limit.
    const MAX_BATCH_BYTES = 80000;
    const FETCH_PAGE_SIZE = 50;

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
        return [
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
        ];
    }
}
