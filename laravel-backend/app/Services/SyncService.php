<?php
namespace App\Services;

use App\Models\Tenant\{Document, SyncJob, Product, Faq, Chunk};
use App\Jobs\EmbedDocumentJob;
use App\Support\SkuNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    // "Is this genuine?" fields — only a line if the seller actually
    // recorded that specific field (see Hamman_Product_Sync::
    // authenticity_fields() on the plugin side); array_filter() above
    // drops any of these that are empty, so an unmapped/unfilled field
    // leaves no trace in the indexed text at all — the grounding rule
    // (rag_service._authenticity_rule()) treats its absence as "not
    // recorded", never guessing a value never actually written here.
    !empty($p['authenticity_status']) ? 'Authenticity Status: '.$p['authenticity_status'] : '',
    !empty($p['brand']) ? 'Brand: '.$p['brand'] : '',
    !empty($p['official_distributor']) ? 'Official Distributor: '.$p['official_distributor'] : '',
    !empty($p['warranty_period']) ? 'Warranty Period: '.$p['warranty_period'] : '',
    !empty($p['country_of_origin']) ? 'Country of Origin: '.$p['country_of_origin'] : '',
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
                    ['name'=>$p['name'],'sku'=>$p['sku']??null,'sku_normalized'=>SkuNormalizer::normalize($p['sku']??null),'type'=>$p['type']??'simple','status'=>$p['status']??'publish','description'=>strip_tags($p['description']??''),'price'=>$p['price']??null,'currency'=>$p['currency']??'USD','stock_status'=>$p['stock_status']??'instock','permalink'=>$p['permalink']??null,'featured_image'=>$p['featured_image']??null,'attributes'=>$p['attributes']??[],'tags'=>$p['tags']??[],'authenticity_status'=>$p['authenticity_status']??null,'brand'=>$p['brand']??null,'official_distributor'=>$p['official_distributor']??null,'warranty_period'=>$p['warranty_period']??null,'country_of_origin'=>$p['country_of_origin']??null,'embedding_status'=>'pending','synced_at'=>now()]
                );
                if (in_array($outcome, ['new','updated'], true)) {
                    EmbedDocumentJob::dispatch($doc->id, $chatbotId, $schema);
                }
                $this->syncAttachments($chatbotId, (int)$p['id'], $p['name'], $p['attachments']??[], $schema);
                $counts[$outcome]++;
            } catch (\Throwable $e) {
                $counts['failed']++;
                $errors[] = ['item'=>$p['id']??'?','error'=>$e->getMessage()];
            }
        }
        $job->update(['status'=>$counts['failed']>0&&($counts['new']+$counts['updated']+$counts['skipped'])===0?'failed':'completed','items_processed'=>$counts['new']+$counts['updated']+$counts['skipped'],'items_failed'=>$counts['failed'],'error_log'=>$errors,'completed_at'=>now(),'result'=>$counts]);
        return $job;
    }

    /**
     * Each PDF attachment (a WordPress media file attached to the product
     * post, or a PDF link found inside its description — see
     * class-hamman-product-sync.php's product_to_array()) becomes its own
     * document, one per distinct URL. raw_content is deliberately left
     * empty here: pdf_service.py (Python) downloads and extracts the
     * actual text at embed time, since that's where the extraction
     * library lives — this method only registers "this file exists and
     * belongs to this product" and dispatches the same EmbedDocumentJob
     * every other document type uses.
     *
     * Known limitation: because raw_content never changes, upsertDoc()'s
     * unchanged-content-hash skip means a file replaced at the *same* URL
     * is never re-embedded — re-syncing only picks up a genuinely new URL.
     * Not solved here; out of scope for what was asked.
     */
    private function syncAttachments(string $chatbotId, int $productId, string $productName, array $attachments, string $schema): void {
        foreach ($attachments as $att) {
            $url = $att['url'] ?? null;
            if (!$url) continue;
            try {
                ['document'=>$doc, 'outcome'=>$outcome] = $this->upsertDoc([
                    'chatbot_id'  => $chatbotId,
                    'source_type' => 'product_attachment',
                    'external_id' => 'pdf_'.md5($url),
                    'title'       => $att['name'] ?? (basename(parse_url($url, PHP_URL_PATH) ?: '') ?: $url),
                    'source_url'  => $url,
                    'raw_content' => '',
                    'metadata'    => [
                        'url'          => $url,
                        'product_id'   => $productId,
                        'product_name' => $productName,
                        'type'         => 'attachment',
                    ],
                ]);
                if (in_array($outcome, ['new','updated'], true)) {
                    EmbedDocumentJob::dispatch($doc->id, $chatbotId, $schema);
                }
            } catch (\Throwable $e) {
                // Best-effort per attachment — one bad URL must not fail
                // the product sync it's attached to.
            }
        }
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
            'order.placed'     => $this->recordOrder($chatbotId, $data),
            default => null,
        };
    }

    /**
     * Revenue attribution (doc-04, prerequisite for Intent analytics) —
     * fired from Hamman_Sync_Manager::on_order_placed() (WooCommerce's
     * woocommerce_thankyou hook) once per real order. conversation_id
     * arrives from a hamman_conv_id browser cookie the plugin read at
     * checkout time — since that value ultimately came from the customer's
     * own browser, it's never trusted blindly: it must look like a real
     * UUID AND actually belong to a conversation on THIS chatbot before
     * being attached to the order, otherwise it's silently dropped rather
     * than attributed to the wrong bot (or a manipulated/stale cookie).
     * UNIQUE(chatbot_id, woo_order_id) (see TenantService) makes this
     * naturally idempotent against WooCommerce re-firing the hook (e.g. a
     * thank-you page refresh) — the id column is only ever set on the
     * initial insert, never touched again, so a repeat call can't silently
     * swap the primary key underneath an existing row.
     */
    private function recordOrder(string $chatbotId, array $data): void {
        $wooOrderId = (int) ($data['order_id'] ?? 0);
        if (!$wooOrderId) return;

        $conversationId = null;
        $rawConvId = $data['conversation_id'] ?? null;
        if ($rawConvId && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $rawConvId)) {
            $belongsToThisChatbot = DB::table('conversations')->where('id', $rawConvId)->where('chatbot_id', $chatbotId)->exists();
            if ($belongsToThisChatbot) $conversationId = $rawConvId;
        }

        $lineItems = [];
        foreach (($data['line_items'] ?? []) as $item) {
            if (!isset($item['product_id'])) continue;
            $lineItems[] = [
                'product_id' => (int) $item['product_id'],
                'quantity'   => (int) ($item['quantity'] ?? 1),
                'total'      => (float) ($item['total'] ?? 0),
            ];
        }

        $fields = [
            'conversation_id' => $conversationId,
            'total'           => (float) ($data['total'] ?? 0),
            'currency'        => $data['currency'] ?? 'IRT',
            'status'          => $data['status'] ?? 'pending',
            'line_items'      => json_encode($lineItems),
        ];

        $existing = DB::table('orders')->where('chatbot_id', $chatbotId)->where('woo_order_id', $wooOrderId)->first();
        if ($existing) {
            DB::table('orders')->where('id', $existing->id)->update($fields);
            return;
        }
        DB::table('orders')->insert(array_merge($fields, [
            'id'           => (string) Str::uuid(),
            'chatbot_id'   => $chatbotId,
            'woo_order_id' => $wooOrderId,
            'created_at'   => now(),
        ]));
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
