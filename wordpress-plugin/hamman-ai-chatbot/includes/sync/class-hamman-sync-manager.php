<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Sync_Manager {
    private function api(): Hamman_Api_Client { return new Hamman_Api_Client(); }
    private function chatbotId(): string { return get_option( 'hamman_chatbot_id', '' ); }
    private function isReady(): bool { return !empty( get_option('hamman_api_key') ) && !empty( $this->chatbotId() ) && get_option('hamman_enabled','1')==='1'; }

    public function run_incremental_sync(): void {
        if (!$this->isReady()) return;
        (new Hamman_Product_Sync($this->api()))->sync_recent($this->chatbotId());
        (new Hamman_Page_Sync($this->api()))->sync_recent($this->chatbotId());
    }

    public function run_full_sync(): array {
        if (!$this->isReady()) return ['error'=>'Not configured'];
        $cid     = $this->chatbotId();
        $api     = $this->api();
        $results = [];
        $results['products'] = (new Hamman_Product_Sync($api))->sync_all($cid);
        $results['pages']    = (new Hamman_Page_Sync($api))->sync_all($cid);
        $results['faqs']     = (new Hamman_Faq_Sync($api))->sync_all($cid);
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
}
