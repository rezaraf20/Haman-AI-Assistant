<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Sync_Manager {
    private function api(): Hamman_Api_Client { return new Hamman_Api_Client(); }
    private function chatbotId(): string { return get_option( 'hamman_chatbot_id', '' ); }
    private function isReady(): bool { return !empty( get_option('hamman_api_key') ) && !empty( $this->chatbotId() ) && get_option('hamman_enabled','1')==='1'; }

    public function run_incremental_sync(): void {
        if (!$this->isReady()) return;
        if (get_option('hamman_sync_products','1') === '1') {
            (new Hamman_Product_Sync($this->api()))->sync_recent($this->chatbotId());
        }
        if (get_option('hamman_sync_pages','1') === '1') {
            (new Hamman_Page_Sync($this->api()))->sync_recent($this->chatbotId());
        }
    }

    public function run_full_sync(): array {
        if (!$this->isReady()) return ['error'=>'Not configured'];
        $cid     = $this->chatbotId();
        $api     = $this->api();
        $results = [];
        // Each type independently opt-out-able from the "همگام‌سازی" tab —
        // a merchant with no WooCommerce products, or who doesn't want
        // page content indexed, shouldn't have to sit through (and pay
        // the embedding cost of) syncing it anyway.
        if (get_option('hamman_sync_products','1') === '1') {
            $results['products'] = (new Hamman_Product_Sync($api))->sync_all($cid);
        }
        if (get_option('hamman_sync_pages','1') === '1') {
            $results['pages'] = (new Hamman_Page_Sync($api))->sync_all($cid);
            $results['faqs']  = (new Hamman_Faq_Sync($api))->sync_all($cid);
        }
        update_option( 'hamman_last_full_sync', time() );
        return $results;
    }

    public function on_product_updated( int $id ): void {
        if (!$this->isReady()) return;
        $p = wc_get_product($id);
        if (!$p) return;
        $data = (new Hamman_Product_Sync($this->api()))->product_to_array($p);
        $this->api()->send_webhook(['event'=>'product.updated','chatbot_id'=>$this->chatbotId(),'data'=>$data]);
    }

    public function on_product_deleted( int $id ): void {
        if (!$this->isReady()) return;
        $this->api()->send_webhook(['event'=>'product.deleted','chatbot_id'=>$this->chatbotId(),'data'=>['id'=>$id]]);
    }

    public function on_post_saved( int $id, \WP_Post $post, bool $update ): void {
        if (wp_is_post_revision($id) || $post->post_status !== 'publish') return;
        if (!in_array($post->post_type, ['page','post'], true)) return;
        if (!$this->isReady()) return;
        $data  = ['id'=>$id,'title'=>get_the_title($id),'content'=>wp_strip_all_tags(get_post_field('post_content',$id)),'url'=>get_permalink($id),'post_type'=>$post->post_type];
        $event = $update ? 'page.updated' : 'page.created';
        $this->api()->send_webhook(['event'=>$event,'chatbot_id'=>$this->chatbotId(),'data'=>$data]);
    }

    // Bound to both delete_post (permanent deletion) and wp_trash_post (the
    // far more common path — most users trash rather than permanently
    // delete) so either one removes the page/post from the bot's content
    // the same way woocommerce_delete_product already does for products.
    // Mirrors on_product_deleted() exactly.
    public function on_post_removed( int $id ): void {
        if (!$this->isReady()) return;
        if (!in_array( get_post_type($id), ['page','post'], true )) return;
        $this->api()->send_webhook(['event'=>'page.deleted','chatbot_id'=>$this->chatbotId(),'data'=>['id'=>$id]]);
    }

    /**
     * Revenue attribution (doc-04, prerequisite for Intent analytics) — the
     * outbound half. Fires once per successful order (woocommerce_thankyou,
     * the standard hook for "the order exists, the customer is looking at
     * the thank-you page") and reports it, WITH the conversation ID from
     * this browser's own hamman_conv_id cookie WHEN ONE IS PRESENT — never
     * fabricated, and never assumed present (a customer who never opened
     * the chat widget, or whose cookie already expired, simply reports no
     * conversation_id, and Laravel's SyncService::recordOrder() treats a
     * missing/invalid one the same safe way). A post meta flag makes this
     * idempotent against the thank-you page being reloaded/revisited.
     */
    public function on_order_placed( int $order_id ): void {
        if ( ! $order_id || ! $this->isReady() ) return;
        if ( ! function_exists( 'wc_get_order' ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( $order->get_meta( '_hamman_reported' ) ) return;

        $conv_id = null;
        if ( isset( $_COOKIE['hamman_conv_id'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_COOKIE['hamman_conv_id'] ) );
            // Only a plausible UUID is ever forwarded — never trust an
            // arbitrary cookie value blindly, even though the server side
            // re-validates this against its own conversations table too.
            if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw ) ) {
                $conv_id = $raw;
            }
        }

        $line_items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $line_items[] = [
                'product_id' => $product ? $product->get_id() : (int) $item->get_product_id(),
                'quantity'   => (int) $item->get_quantity(),
                'total'      => (float) $item->get_total(),
            ];
        }

        $result = $this->api()->send_webhook( [
            'event'      => 'order.placed',
            'chatbot_id' => $this->chatbotId(),
            'data'       => [
                'order_id'        => $order_id,
                'conversation_id' => $conv_id,
                'total'           => (float) $order->get_total(),
                'currency'        => $order->get_currency(),
                'status'          => $order->get_status(),
                'line_items'      => $line_items,
            ],
        ] );

        if ( ! is_wp_error( $result ) ) {
            $order->update_meta_data( '_hamman_reported', 1 );
            $order->save();
        }
    }
}
