<?php
namespace App\Services;
use App\Models\Tenant\{Document, SyncJob, Product, Faq};
use App\Jobs\EmbedDocumentJob;

class SyncService {
    public function syncProducts(string $chatbotId, array $products, string $schema): SyncJob {
        $job = SyncJob::create(['chatbot_id'=>$chatbotId,'job_type'=>'products','triggered_by'=>'plugin','status'=>'running','items_total'=>count($products),'started_at'=>now()]);
        $ok = 0; $fail = 0; $errors = [];
        foreach ($products as $p) {
            try {
                $content = implode("\n", array_filter([
                    'Product: '.$p['name'],
                    'SKU: '.($p['sku']??''),
                    'Price: '.($p['price']??'').' '.($p['currency']??'USD'),
                    'Description: '.strip_tags($p['description']??''),
                    'Category: '.implode(', ', array_column($p['categories']??[], 'name')),
                    'Stock: '.($p['stock_status']??'instock'),
                ]));
                $doc = $this->upsertDoc(['chatbot_id'=>$chatbotId,'source_type'=>'woocommerce_product','external_id'=>(string)$p['id'],'title'=>$p['name'],'raw_content'=>$content,'metadata'=>['price'=>$p['price']??null,'currency'=>$p['currency']??'USD','category'=>($p['categories'][0]['name']??null),'permalink'=>$p['permalink']??null,'image'=>$p['featured_image']??null,'stock_status'=>$p['stock_status']??'instock','type'=>'product']]);
                Product::updateOrCreate(['chatbot_id'=>$chatbotId,'woo_product_id'=>$p['id']],['name'=>$p['name'],'sku'=>$p['sku']??null,'type'=>$p['type']??'simple','status'=>$p['status']??'publish','description'=>strip_tags($p['description']??''),'price'=>$p['price']??null,'currency'=>$p['currency']??'USD','stock_status'=>$p['stock_status']??'instock','permalink'=>$p['permalink']??null,'featured_image'=>$p['featured_image']??null,'attributes'=>$p['attributes']??[],'tags'=>$p['tags']??[],'embedding_status'=>'pending','synced_at'=>now()]);
                if ($doc->status === 'pending') EmbedDocumentJob::dispatch($doc->id, $chatbotId, $schema)->onQueue('embeddings');
                $ok++;
            } catch (\Throwable $e) { $fail++; $errors[] = ['item'=>$p['id']??'?','error'=>$e->getMessage()]; }
        }
        $job->update(['status'=>$fail>0&&$ok===0?'failed':'completed','items_processed'=>$ok,'items_failed'=>$fail,'error_log'=>$errors,'completed_at'=>now(),'result'=>['indexed'=>$ok,'failed'=>$fail]]);
        return $job;
    }

    public function syncPages(string $chatbotId, array $pages, string $schema): SyncJob {
        $job = SyncJob::create(['chatbot_id'=>$chatbotId,'job_type'=>'pages','triggered_by'=>'plugin','status'=>'running','items_total'=>count($pages),'started_at'=>now()]);
        $ok = 0;
        foreach ($pages as $page) {
            try {
                $content = strip_tags($page['content']??$page['excerpt']??'');
                $doc = $this->upsertDoc(['chatbot_id'=>$chatbotId,'source_type'=>($page['post_type']??'page')==='post'?'wordpress_post':'wordpress_page','external_id'=>(string)$page['id'],'title'=>$page['title'],'raw_content'=>$page['title']."\n\n".$content,'metadata'=>['url'=>$page['url']??null,'type'=>'page']]);
                if ($doc->status === 'pending') EmbedDocumentJob::dispatch($doc->id, $chatbotId, $schema)->onQueue('embeddings');
                $ok++;
            } catch (\Throwable $e) {}
        }
        $job->update(['status'=>'completed','items_processed'=>$ok,'completed_at'=>now()]);
        return $job;
    }

    public function syncFaqs(string $chatbotId, array $faqs, string $schema): SyncJob {
        $job = SyncJob::create(['chatbot_id'=>$chatbotId,'job_type'=>'faqs','triggered_by'=>'plugin','status'=>'running','items_total'=>count($faqs),'started_at'=>now()]);
        $ok = 0;
        foreach ($faqs as $faq) {
            try {
                Faq::updateOrCreate(['chatbot_id'=>$chatbotId,'question'=>$faq['question']],['answer'=>$faq['answer'],'category'=>$faq['category']??null,'source'=>'wordpress_page','is_active'=>true]);
                $doc = $this->upsertDoc(['chatbot_id'=>$chatbotId,'source_type'=>'faq','external_id'=>'faq_'.md5($faq['question']),'title'=>$faq['question'],'raw_content'=>"Q: {$faq['question']}\nA: {$faq['answer']}",'metadata'=>['type'=>'faq']]);
                if ($doc->status === 'pending') EmbedDocumentJob::dispatch($doc->id, $chatbotId, $schema)->onQueue('embeddings');
                $ok++;
            } catch (\Throwable $e) {}
        }
        $job->update(['status'=>'completed','items_processed'=>$ok,'completed_at'=>now()]);
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
            default => null,
        };
    }

    private function upsertDoc(array $d): Document {
        $hash    = hash('sha256', $d['raw_content']);
        $ex      = Document::where('chatbot_id', $d['chatbot_id'])->where('source_type', $d['source_type'])->where('external_id', $d['external_id'])->first();
        $changed = !$ex || $ex->content_hash !== $hash;
        return Document::updateOrCreate(
            ['chatbot_id'=>$d['chatbot_id'],'source_type'=>$d['source_type'],'external_id'=>$d['external_id']],
            ['title'=>$d['title'],'source_url'=>$d['source_url']??null,'raw_content'=>$d['raw_content'],'content_hash'=>$hash,'metadata'=>$d['metadata']??[],'status'=>$changed?'pending':'indexed','last_synced_at'=>now()]
        );
    }
}
