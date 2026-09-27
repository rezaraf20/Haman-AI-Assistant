<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Haman_Faq_Sync {
    // See Haman_Page_Sync::MAX_BATCH_BYTES — batches sized by byte length, not
    // item count, to stay under the host's ~128KB WAF request-body limit.
    const MAX_BATCH_BYTES = 80000;

    public function __construct( private Haman_Api_Client $api ) {}

    public function sync_all( string $cid ): array {
        $faqs = $this->extract_faqs();
        // Same shape as an empty result below (all-zero counts), so the
        // admin results table shows a real zero row instead of a key it
        // doesn't recognize.
        if (empty($faqs)) return Haman_Sync_Counts::empty();
        $counts = Haman_Sync_Counts::empty();
        $errors = []; $batch = []; $batchBytes = 0;
        foreach ($faqs as $faq) {
            $itemSize = strlen(wp_json_encode($faq));
            if (!empty($batch) && ($batchBytes + $itemSize) > self::MAX_BATCH_BYTES) {
                $this->flush_batch($cid, $batch, $counts, $errors);
                $batch = []; $batchBytes = 0;
            }
            $batch[]     = $faq;
            $batchBytes += $itemSize;
        }
        $this->flush_batch($cid, $batch, $counts, $errors);
        $result = $counts;
        if (!empty($errors)) $result['errors'] = array_values(array_unique($errors));
        return $result;
    }

    /**
     * Sums whatever new/updated/skipped/deleted/failed counts the platform
     * actually computed for this batch (SyncJob.result, now returned in the
     * API response — see SyncController::jobArr()) instead of just counting
     * how many items this batch attempted to push. A full sync may flush
     * several batches, each its own SyncJob, so these accumulate across
     * calls into sync_all()'s final total.
     */
    private function flush_batch( string $cid, array $batch, array &$counts, array &$errors ): void {
        if (empty($batch)) return;
        $r = $this->api->sync_faqs($cid, $batch);
        if (is_wp_error($r)) { $errors[] = $r->get_error_message(); return; }
        Haman_Sync_Counts::add($counts, is_array($r) ? ($r['result'] ?? []) : []);
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
