<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Page_Sync {
    // Some hosts' front-end WAF (ModSecurity's default SecRequestBodyNoFilesLimit)
    // rejects any POST body over 128KB with no useful error, which — depending on
    // timing — surfaces as an empty request body, a 408, or a 504. Elementor page
    // content varies wildly in size, so batches are sized by actual encoded byte
    // length rather than a fixed item count, staying safely under that limit.
    const MAX_BATCH_BYTES = 80000;
    const FETCH_PAGE_SIZE = 50;

    public function __construct( private Hamman_Api_Client $api ) {}

    public function sync_all( string $cid ): array {
        $page = 1; $synced = 0; $errors = []; $batch = []; $batchBytes = 0;
        do {
            $posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','posts_per_page'=>self::FETCH_PAGE_SIZE,'paged'=>$page]);
            foreach ($posts as $post) {
                $item     = $this->post_to_array($post);
                $itemSize = strlen(wp_json_encode($item));
                if (!empty($batch) && ($batchBytes + $itemSize) > self::MAX_BATCH_BYTES) {
                    $this->flush_batch($cid, $batch, $synced, $errors);
                    $batch = []; $batchBytes = 0;
                }
                $batch[]     = $item;
                $batchBytes += $itemSize;
            }
            $page++;
        } while (count($posts) === self::FETCH_PAGE_SIZE);
        $this->flush_batch($cid, $batch, $synced, $errors);
        $result = ['synced'=>$synced];
        if (!empty($errors)) $result['errors'] = array_values(array_unique($errors));
        return $result;
    }

    private function flush_batch( string $cid, array $batch, int &$synced, array &$errors ): void {
        if (empty($batch)) return;
        $r = $this->api->sync_pages($cid, $batch);
        if (is_wp_error($r)) { $errors[] = $r->get_error_message(); }
        else { $synced += count($batch); }
    }

    public function sync_recent( string $cid, int $hours=25 ): void {
        $posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','posts_per_page'=>self::FETCH_PAGE_SIZE,'date_query'=>[['after'=>date('Y-m-d H:i:s',strtotime("-{$hours} hours"))]]]);
        if (empty($posts)) return;
        $synced = 0; $errors = [];
        $this->flush_batch($cid, array_map([$this,'post_to_array'],$posts), $synced, $errors);
    }

    public function post_to_array( \WP_Post $p ): array {
        return [
            'id'        => $p->ID,
            'title'     => get_the_title($p->ID),
            'content'   => $this->extract_content($p),
            'url'       => get_permalink($p->ID),
            'post_type' => $p->post_type,
        ];
    }

    private function extract_content( \WP_Post $p ): string {
        // 1. Elementor via JSON data
        $elementor_data = get_post_meta($p->ID, '_elementor_data', true);
        if (!empty($elementor_data)) {
            $data = json_decode($elementor_data, true);
            if (is_array($data)) {
                $text = '';
                $this->extract_elementor_text($data, $text);
                $text = $this->clean_content($text);
                if (!empty($text)) return $text;
            }
        }

        // 2. Elementor frontend render
        if (class_exists('\Elementor\Plugin')) {
            $rendered = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($p->ID, true);
            $text = $this->clean_content(wp_strip_all_tags($rendered));
            if (!empty($text)) return $text;
        }

        // 3. Standard WordPress
        $content = apply_filters('the_content', $p->post_content);
        $text = trim(wp_strip_all_tags($content));
        if (!empty($text)) return $text;

        // 4. Raw post_content
        $text = trim(wp_strip_all_tags($p->post_content));
        if (!empty($text)) return $text;

        return $p->post_excerpt ?: get_the_title($p->ID);
    }

    private function extract_elementor_text( array $elements, string &$text ): void {
        foreach ($elements as $el) {
            if (!empty($el['settings'])) {
                foreach ($el['settings'] as $key => $value) {
                    if (is_string($value) && strlen($value) > 3 && !$this->is_css_or_url($value)) {
                        $text .= ' ' . wp_strip_all_tags($value);
                    }
                }
            }
            if (!empty($el['elements'])) {
                $this->extract_elementor_text($el['elements'], $text);
            }
        }
    }

    private function clean_content( string $text ): string {
        $css_words = ['classic','full','none','initial','center','start','flex-end','wrap',
                      'inherit','justify','column','row','span','slideInUp','slow','italic',
                      'custom','space-around','flex','absolute','relative','fixed','sticky',
                      'block','inline','grid','auto','normal','bold','uppercase','lowercase'];
        foreach ($css_words as $word) {
            $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $text);
        }
        $text = preg_replace('/\b(Playfair Display|Poppins|Arial|Roboto|Open Sans|Lato)\b/i', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function is_css_or_url( string $value ): bool {
        return preg_match('/^(#|rgb|http|https|data:|\.|\d+px|\d+%)/i', $value)
            || strpos($value, '{') !== false
            || (strpos($value, ':') !== false && strlen($value) < 50);
    }
}