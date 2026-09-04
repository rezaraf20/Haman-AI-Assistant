<?php
namespace App\Services;

use App\Models\Tenant\{Document, SyncJob, Product, Faq, Chunk};
use App\Jobs\EmbedDocumentJob;

class SyncService {

    public function syncProducts(string $chatbotId, array $products, string $schema): SyncJob {
        $job = SyncJob::create(['chatbot_id'=>$chatbotId,'job_type'=>'products','triggered_by'=>'plugin','status'=>'running','items_total'=>count($products),'started_at'=>now()]);
        $counts = ['new'=>0,'updated'=>0,'skipped'=>0,'deleted'=>0,'failed'=>0];
        $errors = [];
        foreach ($products as $p) {
            try {
                $content = implode("\n", array_filter([
    'Product: '.$p['name'],
    'SKU: '.($p['sku']??''),
    'Price: '.($p['price']??'').' '.($p['currency']??'IRT'),
    'Description: '.strip_tags($p['description']??''),
    'Short Description: '.strip_tags($p['short_description']??''),
    'Category: '.implode(', ', array_column($p['categories']??[], 'name')),
    'Tags: '.implode(', ', $p['tags']??[]),
    'Stock: '.($p['stock_status']??'instock'),
    'Link: '.($p['permalink']??''),
    'Image: '.($p['featured_image']??''),
]));
                ['document'=>$doc, 'outcome'=>$outcome] = $this->upsertDoc([
                    'chatbot_id'  => $chatbotId,
                    'source_type' => 'woocommerce_product',
                    'external_id' => (string)$p['id'],
                    'title'       => $p['name'],
                    'raw_content' => $content,
                    'metadata'    => [
                        'price'        => $p['price']??null,
                        'currency'     => $p['currency']??'USD',
                        'category'     => ($p['categories'][0]['name']??null),
                        'permalink'    => $p['permalink']??null,
                        'image'        => $p['featured_image']??null,
                        'stock_status' => $p['stock_status']??'instock',
                        'type'         => 'product',
                    ],
                ]);
                Product::updateOrCreate(
                    ['chatbot_id'=>$chatbotId,'woo_product_id'=>$p['id']],
                    ['name'=>$p['name'],'sku'=>$p['sku']??null,'type'=>$p['type']??'simple','status'=>$p['status']??'publish','description'=>strip_tags($p['description']??''),'price'=>$p['price']??null,'currency'=>$p['currency']??'USD','stock_status'=>$p['stock_status']??'instock','permalink'=>$p['permalink']??null,'featured_image'=>$p['featured_image']??null,'attributes'=>$p['attributes']??[],'tags'=>$p['tags']??[],'embedding_status'=>'pending','synced_at'=>now()]
                );
                if (in_array($outcome, ['new','updated'], true)) {
                    EmbedDocumentJob::dispatch($doc->id, $chatbotId, $schema);
                }
                $counts[$outcome]++;
            } catch (\Throwable $e) {
                $counts['failed']++;
                $errors[] = ['item'=>$p['id']??'?','error'=>$e->getMessage()];
            }
        }
        $job->update(['status'=>$counts['failed']>0&&($counts['new']+$counts['updated']+$counts['skipped'])===0?'failed':'completed','items_processed'=>$counts['new']+$counts['updated']+$counts['skipped'],'items_failed'=>$counts['failed'],'error_log'=>$errors,'completed_at'=>now(),'result'=>$counts]);
        return $job;
    }

    public function syncPages(string $chatbotId, array $pages, string $schema): SyncJob {
        $job = SyncJob::create(['chatbot_id'=>$chatbotId,'job_type'=>'pages','triggered_by'=>'plugin','status'=>'running','items_total'=>count($pages),'started_at'=>now()]);
        $counts = ['new'=>0,'updated'=>0,'skipped'=>0,'deleted'=>0,'failed'=>0];
        foreach ($pages as $page) {
            try {
                $content = strip_tags($page['content'] ?? '');
                if (empty(trim($content))) $content = $page['excerpt'] ?? '';
                if (empty(trim($content))) $content = $page['title'];

                ['document'=>$doc, 'outcome'=>$outcome] = $this->upsertDoc([
                    'chatbot_id'  => $chatbotId,
                    'source_type' => ($page['post_type']??'page') === 'post' ? 'wordpress_post' : 'wordpress_page',
                    'external_id' => (string)$page['id'],
                    'title'       => $page['title'],
                    'raw_content' => $page['title']."\n\n".$content,
                    'metadata'    => ['url'=>$page['url']??null,'type'=>'page'],
                ]);
                if (in_array($outcome, ['new','updated'], true)) {
                    EmbedDocumentJob::dispatch($doc->id, $chatbotId, $schema);
                }
                $counts[$outcome]++;
            } catch (\Throwable $e) { $counts['failed']++; }
        }
        $job->update(['status'=>'completed','items_processed'=>$counts['new']+$counts['updated']+$counts['skipped'],'completed_at'=>now(),'result'=>$counts]);
        return $job;
    }

    public function syncFaqs(string $chatbotId, array $faqs, string $schema): SyncJob {
        $job = SyncJob::create(['chatbot_id'=>$chatbotId,'job_type'=>'faqs','triggered_by'=>'plugin','status'=>'running','items_total'=>count($faqs),'started_at'=>now()]);
        $counts = ['new'=>0,'updated'=>0,'skipped'=>0,'deleted'=>0,'failed'=>0];
        foreach ($faqs as $faq) {
            try {
                Faq::updateOrCreate(
                    ['chatbot_id'=>$chatbotId,'question'=>$faq['question']],
                    ['answer'=>$faq['answer'],'category'=>$faq['category']??null,'source'=>'wordpress_page','is_active'=>true]
                );
                ['document'=>$doc, 'outcome'=>$outcome] = $this->upsertDoc([
                    'chatbot_id'  => $chatbotId,
                    'source_type' => 'faq',
                    'external_id' => 'faq_'.md5($faq['question']),
                    'title'       => $faq['question'],
                    'raw_content' => "Q: {$faq['question']}\nA: {$faq['answer']}",
                    'metadata'    => ['type'=>'faq'],
                ]);
                if (in_array($outcome, ['new','updated'], true)) {
                    EmbedDocumentJob::dispatch($doc->id, $chatbotId, $schema);
                }
                $counts[$outcome]++;
            } catch (\Throwable $e) { $counts['failed']++; }
        }
        $job->update(['status'=>'completed','items_processed'=>$counts['new']+$counts['updated']+$counts['skipped'],'completed_at'=>now(),'result'=>$counts]);
        return $job;
    }

    public function processWebhook(array $payload, string $schema): ?SyncJob {
        $event     = $payload['event']??'';
        $chatbotId = $payload['chatbot_id']??null;
        $data      = $payload['data']??[];
        if (!$chatbotId) return null;
        return match($event) {
            'product.updated','product.created' => $this->syncProducts($chatbotId, [$data], $schema),
            'page.updated','page.created'       => $this->syncPages($chatbotId, [$data], $schema),
            'faq.updated'                       => $this->syncFaqs($chatbotId, [$data], $schema),
            'product.deleted' => $this->deleteDocument($chatbotId, 'woocommerce_product', (string)($data['id']??''), $data['id']??null),
            'page.deleted'     => $this->deleteDocument($chatbotId, null, (string)($data['id']??''), null),
            default => null,
        };
    }

    /**
     * Handles a real-time "this no longer exists at the source" signal
     * (woocommerce_delete_product / delete_post / wp_trash_post on the
     * WordPress side — see Hamman_Sync_Manager) — archives the matching
     * document (soft: status flips to 'archived', never hard-deleted, same
     * conservative posture as chatbot deletion elsewhere in this app) and
     * removes its chunks so the bot stops citing content that's gone.
     * $sourceType is null for pages, since the external_id alone doesn't
     * say whether it was a 'wordpress_page' or 'wordpress_post' — matched on
     * external_id (+ optionally woo_product_id for the product-row cleanup)
     * across both instead.
     */
    private function deleteDocument(string $chatbotId, ?string $sourceType, string $externalId, ?int $wooProductId): SyncJob {
        $job = SyncJob::create(['chatbot_id'=>$chatbotId,'job_type'=>'deletion','triggered_by'=>'webhook','status'=>'running','items_total'=>1,'started_at'=>now()]);
        $counts = ['new'=>0,'updated'=>0,'skipped'=>0,'deleted'=>0,'failed'=>0];

        try {
            $query = Document::where('chatbot_id', $chatbotId)->where('external_id', $externalId)->where('status', '!=', 'archived');
            if ($sourceType) $query->where('source_type', $sourceType);
            else $query->whereIn('source_type', ['wordpress_page', 'wordpress_post']);

            $doc = $query->first();
            if ($doc) {
                Chunk::where('document_id', $doc->id)->delete();
                $doc->update(['status' => 'archived']);
                $counts['deleted']++;
            }
            if ($wooProductId !== null) {
                Product::where('chatbot_id', $chatbotId)->where('woo_product_id', $wooProductId)->delete();
            }
        } catch (\Throwable $e) {
            $counts['failed']++;
        }

        $job->update(['status'=>'completed','items_processed'=>1,'completed_at'=>now(),'result'=>$counts]);
        return $job;
    }

    /**
     * @return array{document: Document, outcome: 'new'|'updated'|'skipped'}
     */
    private function upsertDoc(array $d): array {
        $hash = hash('sha256', $d['raw_content']);
        $existing = Document::where([
            'chatbot_id'  => $d['chatbot_id'],
            'source_type' => $d['source_type'],
            'external_id' => $d['external_id'],
        ])->first();

        // Content byte-for-byte identical to what's already indexed — no
        // point re-embedding (that's the actual cost: one external
        // embedding-API call per chunk, per EmbedDocumentJob/embed.py) for
        // text that hasn't changed since the last sync. A re-activated
        // document (was archived, now present at the source again) still
        // needs its chunks rebuilt, since deleteDocument() above already
        // removed them — so that case falls through to a real update, not
        // a skip, even if the hash happens to match.
        if ($existing && $existing->content_hash === $hash && $existing->status !== 'archived') {
            $existing->update(['last_synced_at' => now()]);
            return ['document' => $existing, 'outcome' => 'skipped'];
        }

        $outcome = $existing ? 'updated' : 'new';
        $doc = Document::updateOrCreate(
            [
                'chatbot_id'  => $d['chatbot_id'],
                'source_type' => $d['source_type'],
                'external_id' => $d['external_id'],
            ],
            [
                'title'          => $d['title'],
                'source_url'     => $d['source_url'] ?? null,
                'raw_content'    => $d['raw_content'],
                'content_hash'   => $hash,
                'metadata'       => $d['metadata'] ?? [],
                'status'         => 'pending',
                'last_synced_at' => now(),
            ]
        );
        return ['document' => $doc, 'outcome' => $outcome];
    }
}
