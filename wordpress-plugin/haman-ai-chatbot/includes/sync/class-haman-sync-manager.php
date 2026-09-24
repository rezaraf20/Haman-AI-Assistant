<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Haman_Sync_Manager {
    private function api(): Haman_Api_Client { return new Haman_Api_Client(); }
    private function chatbotId(): string { return get_option( 'haman_chatbot_id', '' ); }
    private function isReady(): bool { return !empty( get_option('haman_api_key') ) && !empty( $this->chatbotId() ) && get_option('haman_enabled','1')==='1'; }

    public function run_incremental_sync(): void {
        if (!$this->isReady()) return;
        if (get_option('haman_sync_products','1') === '1') {
            (new Haman_Product_Sync($this->api()))->sync_recent($this->chatbotId());
        }
        // Pages and posts are two independent toggles now (see
        // Haman_Page_Sync::enabled_post_types()'s own docblock) -- this
        // class only needs to know whether EITHER is on, since the actual
        // per-type and per-category filtering happens inside the query
        // Haman_Page_Sync itself builds.
        if (!empty(Haman_Page_Sync::enabled_post_types())) {
            (new Haman_Page_Sync($this->api()))->sync_recent($this->chatbotId());
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
        if (get_option('haman_sync_products','1') === '1') {
            $results['products'] = (new Haman_Product_Sync($api))->sync_all($cid);
        }
        if (!empty(Haman_Page_Sync::enabled_post_types())) {
            $results['pages'] = (new Haman_Page_Sync($api))->sync_all($cid);
            $results['faqs']  = (new Haman_Faq_Sync($api))->sync_all($cid);
        }
        update_option( 'haman_last_full_sync', time() );
        return $results;
    }

    public function on_product_updated( int $id ): void {
        if (!$this->isReady()) return;
        $p = wc_get_product($id);
        if (!$p) return;
        $data = (new Haman_Product_Sync($this->api()))->product_to_array($p);
        $this->api()->send_webhook(['event'=>'product.updated','chatbot_id'=>$this->chatbotId(),'data'=>$data]);
    }

    public function on_product_deleted( int $id ): void {
        if (!$this->isReady()) return;
        $this->api()->send_webhook(['event'=>'product.deleted','chatbot_id'=>$this->chatbotId(),'data'=>['id'=>$id]]);
    }

    public function on_post_saved( int $id, \WP_Post $post, bool $update ): void {
        if (wp_is_post_revision($id) || $post->post_status !== 'publish') return;
        // Same check the bulk sync queries make (Haman_Page_Sync::is_allowed())
        // -- a post type that's off, a specifically excluded page, or a post
        // in an excluded category must not sneak into the index just
        // because it was saved rather than caught by the next full/recent
        // sync.
        if (!Haman_Page_Sync::is_allowed($post)) return;
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
        // A post type this site was never configured to sync in the first
        // place is fine to just ignore here (it can't be in the index).
        // Whether a NOW-excluded page/category is still in the index isn't
        // this webhook's job to reconcile -- it fires only on a real
        // delete/trash, not on a settings change; see SyncSettings' "Clear
        // and reindex" for the latter.
        if (!in_array( get_post_type($id), ['page','post'], true )) return;
        $this->api()->send_webhook(['event'=>'page.deleted','chatbot_id'=>$this->chatbotId(),'data'=>['id'=>$id]]);
    }

    /**
     * Revenue attribution (doc-04, prerequisite for Intent analytics) — the
     * outbound half. Fires once per successful order (woocommerce_thankyou,
     * the standard hook for "the order exists, the customer is looking at
     * the thank-you page") and reports it, WITH the conversation ID from
     * this browser's own haman_conv_id cookie WHEN ONE IS PRESENT — never
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
        if ( Haman_Legacy::meta( $order->get_id(), '_haman_reported' ) ) return;

        $conv_id = null;
        if ( isset( $_COOKIE['haman_conv_id'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_COOKIE['haman_conv_id'] ) );
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
            $order->update_meta_data( '_haman_reported', 1 );
            $order->save();
        }
    }

    /**
     * create_payment_link (doc-04) — keeps the customer portal's "Orders
     * created from chat" view showing a real, live payment status: fires
     * on EVERY status change (paid, cancelled by the auto-cancel cron
     * below, cancelled manually by the merchant, etc.), but ONLY for
     * orders THIS integration created — never for a merchant's own
     * regular checkout orders, which on_order_placed() above already
     * reports once, at thank-you time. Reuses the exact same 'order.placed'
     * event/handler (SyncService::recordOrder() upserts by
     * (chatbot_id, woo_order_id)), so this is really an update to the row
     * that already exists, not a new order being invented.
     */
    public function on_order_status_changed( int $order_id, string $from, string $to ): void {
        if ( ! $order_id || ! $this->isReady() ) return;
        if ( ! function_exists( 'wc_get_order' ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order || ! Haman_Legacy::meta( $order->get_id(), '_haman_created' ) ) return;

        $line_items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $line_items[] = [
                'product_id' => $product ? $product->get_id() : (int) $item->get_product_id(),
                'quantity'   => (int) $item->get_quantity(),
                'total'      => (float) $item->get_total(),
            ];
        }

        // Deliberately no conversation_id here — Laravel already recorded
        // it directly when it created this order (see ChatController::
        // createPaymentLink()), and SyncService::recordOrder()'s update
        // path never touches a conversation_id it wasn't itself given, so
        // a bare status update can never accidentally erase it.
        $this->api()->send_webhook( [
            'event'      => 'order.placed',
            'chatbot_id' => $this->chatbotId(),
            'data'       => [
                'order_id'   => $order_id,
                'total'      => (float) $order->get_total(),
                'currency'   => $order->get_currency(),
                'status'     => $to,
                'line_items' => $line_items,
            ],
        ] );
    }

    /**
     * create_payment_link's auto-cancel rule: an unpaid draft order this
     * integration created must not hold real stock hostage forever. Scans
     * for 'pending' orders flagged _haman_created older than the
     * configured hold period (Advanced tab, default 24h) and cancels
     * them — update_status() itself fires woocommerce_order_status_changed,
     * so on_order_status_changed() above reports the cancellation back
     * automatically, no separate webhook call needed here.
     */
    public function cancel_stale_draft_orders(): void {
        if ( ! $this->isReady() || ! class_exists( 'WooCommerce' ) ) return;

        $hold_hours = max( 1, (int) get_option( 'haman_payment_link_hold_hours', 24 ) );
        $cutoff     = time() - ( $hold_hours * HOUR_IN_SECONDS );

        // Either marker: an order created by 1.9.0 carries the old meta
        // key, and leaving it out would hold its stock forever.
        $orders = wc_get_orders( [
            'status'       => 'pending',
            'date_created' => '<' . $cutoff,
            'meta_query'   => [
                'relation' => 'OR',
                [ 'key' => '_haman_created', 'value' => 1 ],
                [ 'key' => Haman_Legacy::old_key( '_haman_created' ), 'value' => 1 ],
            ],
            'limit'        => 50,
            'return'       => 'objects',
        ] );

        foreach ( $orders as $order ) {
            $order->update_status(
                'cancelled',
                __( 'Auto-cancelled by Haman AI: unpaid draft order created from chat exceeded the hold period.', 'haman-ai-chatbot' )
            );
        }
    }
}
