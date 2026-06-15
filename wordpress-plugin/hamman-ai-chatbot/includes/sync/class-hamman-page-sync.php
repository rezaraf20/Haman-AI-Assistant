<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Page_Sync {
    public function __construct( private Hamman_Api_Client $api ) {}

    public function sync_all( string $cid ): array {
        $page=1; $synced=0;
        do {
            $posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','posts_per_page'=>50,'paged'=>$page]);
            if (empty($posts)) break;
            $r = $this->api->sync_pages($cid, array_map([$this,'post_to_array'],$posts));
            if (!is_wp_error($r)) $synced += count($posts);
            $page++;
        } while (count($posts) === 50);
        return ['synced'=>$synced];
    }

    public function sync_recent( string $cid, int $hours=25 ): void {
        $posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','posts_per_page'=>50,'date_query'=>[['after'=>date('Y-m-d H:i:s',strtotime("-{$hours} hours"))]]]);
        if (empty($posts)) return;
        $this->api->sync_pages($cid, array_map([$this,'post_to_array'],$posts));
    }

    private function post_to_array( \WP_Post $p ): array {
        return ['id'=>$p->ID,'title'=>get_the_title($p->ID),'content'=>wp_strip_all_tags(apply_filters('the_content',$p->post_content)),'url'=>get_permalink($p->ID),'post_type'=>$p->post_type];
    }
}
