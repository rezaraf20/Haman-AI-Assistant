<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Faq_Sync {
    public function __construct( private Hamman_Api_Client $api ) {}

    public function sync_all( string $cid ): array {
        $faqs = $this->extract_faqs();
        if (empty($faqs)) return ['synced'=>0];
        $r = $this->api->sync_faqs($cid, $faqs);
        return is_wp_error($r) ? ['error'=>$r->get_error_message()] : ['synced'=>count($faqs)];
    }

    private function extract_faqs(): array {
        $faqs  = [];
        $posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','posts_per_page'=>-1]);
        foreach ($posts as $post) {
            $blocks = parse_blocks($post->post_content);
            foreach ($blocks as $block) {
                if ($block['blockName'] === 'yoast/faq-block') {
                    foreach ($block['attrs']['questions']??[] as $q) {
                        if (!empty($q['jsonQuestion']) && !empty($q['jsonAnswer'])) {
                            $faqs[] = ['question'=>wp_strip_all_tags($q['jsonQuestion']),'answer'=>wp_strip_all_tags($q['jsonAnswer'])];
                        }
                    }
                }
            }
        }
        if (post_type_exists('faq')) {
            $faq_posts = get_posts(['post_type'=>'faq','post_status'=>'publish','posts_per_page'=>-1]);
            foreach ($faq_posts as $p) {
                $faqs[] = ['question'=>get_the_title($p->ID),'answer'=>wp_strip_all_tags(apply_filters('the_content',$p->post_content))];
            }
        }
        return $faqs;
    }
}
