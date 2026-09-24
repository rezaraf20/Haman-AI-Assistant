<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Haman_Page_Sync {
    // Some hosts' front-end WAF (ModSecurity's default SecRequestBodyNoFilesLimit)
    // rejects any POST body over 128KB with no useful error, which — depending on
    // timing — surfaces as an empty request body, a 408, or a 504. Elementor page
    // content varies wildly in size, so batches are sized by actual encoded byte
    // length rather than a fixed item count, staying safely under that limit.
    const MAX_BATCH_BYTES = 80000;
    const FETCH_PAGE_SIZE = 50;

    public function __construct( private Haman_Api_Client $api ) {}

    /**
     * Which post types this site's own settings currently allow syncing at
     * all. Pages and posts used to be one combined "haman_sync_pages"
     * option (get_posts(['post_type'=>['page','post'],...]) unconditionally)
     * -- the actual cause of a real incident: a hamantech.ir blog post's
     * pricing table got indexed and surfaced mid-conversation to a customer
     * who never asked about it, because there was no way to sync pages
     * without also syncing every blog post. Posts default OFF for exactly
     * that reason; pages keep the old default (on), so an existing install
     * upgrading to this version sees no behavior change for pages.
     */
    public static function enabled_post_types(): array {
        $types = [];
        if (get_option('haman_sync_pages', '1') === '1') $types[] = 'page';
        if (get_option('haman_sync_posts', '0') === '1') $types[] = 'post';
        return $types;
    }

    private static function excluded_ids( string $option ): array {
        $raw = get_option($option, '');
        if (!$raw) return [];
        return array_values(array_filter(array_map('intval', array_map('trim', explode(',', $raw)))));
    }

    /**
     * The single place both the bulk queries below AND sync-manager's
     * real-time save/delete webhooks ask "is this specific post allowed to
     * sync right now" -- so a page excluded by ID, or a post in an excluded
     * category, is treated identically whether it arrives via a full sync
     * or a single wp_insert_post save, instead of the two paths quietly
     * drifting apart.
     */
    public static function is_allowed( \WP_Post $post ): bool {
        if (!in_array($post->post_type, self::enabled_post_types(), true)) return false;

        if ($post->post_type === 'page') {
            return !in_array($post->ID, self::excluded_ids('haman_sync_excluded_page_ids'), true);
        }

        if ($post->post_type === 'post') {
            $excluded = self::excluded_ids('haman_sync_excluded_category_ids');
            if (empty($excluded)) return true;
            $categories = wp_get_post_categories($post->ID, ['fields' => 'ids']);
            return empty(array_intersect($categories, $excluded));
        }

        return true;
    }

    private function query_args( int $per_page, int $paged, array $extra = [] ): array {
        $post_types = self::enabled_post_types();
        $args = array_merge([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
        ], $extra);

        $excluded_pages = self::excluded_ids('haman_sync_excluded_page_ids');
        if (!empty($excluded_pages)) {
            $args['post__not_in'] = array_merge($args['post__not_in'] ?? [], $excluded_pages);
        }

        // tax_query has no effect on 'page' (pages carry no 'category'
        // terms), so this only ever filters the 'post' half of a mixed
        // query -- no separate branch needed for the pages-only case.
        $excluded_cats = self::excluded_ids('haman_sync_excluded_category_ids');
        if (!empty($excluded_cats) && in_array('post', $post_types, true)) {
            $args['tax_query'] = [[
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => $excluded_cats,
                'operator' => 'NOT IN',
            ]];
        }

        return $args;
    }

    public function sync_all( string $cid ): array {
        if (empty(self::enabled_post_types())) return ['synced' => 0];

        $page = 1; $synced = 0; $errors = []; $batch = []; $batchBytes = 0;
        do {
            $posts = get_posts($this->query_args(self::FETCH_PAGE_SIZE, $page));
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
        if (empty(self::enabled_post_types())) return;
        $posts = get_posts($this->query_args(self::FETCH_PAGE_SIZE, 1, [
            'date_query' => [['after' => date('Y-m-d H:i:s', strtotime("-{$hours} hours"))]],
        ]));
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
                      'block','inline','grid','auto','normal','bold','uppercase','lowercase',
                      // Widget/box style presets and shape-divider/background
                      // shorthand fragments seen leaking into real synced
                      // content (see is_css_or_url()'s comment for the real
                      // incident this traces back to) — kept here too as a
                      // second layer, in case a future extraction path
                      // (e.g. the rendered-HTML fallback below) reintroduces
                      // the same words a different way.
                      'solid','glass','middle','outline','gradient','rotate','flip',
                      'underline','no-repeat','cover','inset','top','bottom','wave'];
        foreach ($css_words as $word) {
            $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $text);
        }
        $text = preg_replace('/\b(Playfair Display|Poppins|Arial|Roboto|Open Sans|Lato)\b/i', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function is_css_or_url( string $value ): bool {
        if (preg_match('/^(#|rgb|http|https|data:|\.|\d+px|\d+%)/i', $value)) return true;
        if (strpos($value, '{') !== false) return true;
        if (strpos($value, ':') !== false && strlen($value) < 50) return true;

        // The real incident this guards against: a live customer's bot
        // answered "what's your company name?" with a fabricated competitor
        // name that turned out to be nothing of the sort — it was the site's
        // own Elementor theme/addon's internal skin identifier ("HamanCo"),
        // scraped from a widget setting alongside dozens of other internal
        // tokens ("glass", "middle", "solid", "ServiceButton", "hamanb")
        // that all made it past the checks above because none of them look
        // like a URL, a color, or a CSS declaration.
        //
        // What they all share instead: Elementor/theme/addon internal
        // identifiers (skin names, widget-type slugs, style/animation
        // presets, element IDs) are almost always a single space-free ASCII
        // token. Real authored text is either Persian/Arabic (never matches
        // this pattern at all) or an English phrase that — even when short
        // ("About Us", "Get Started") — contains a space or punctuation.
        // A bare, punctuation-free run of Latin letters/digits is the
        // signature of an internal value, not something a site owner typed
        // for a visitor to read.
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $value)) return true;

        return false;
    }
}