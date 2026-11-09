<?php
namespace App\Http\Controllers\Api\V1;

use App\Services\SyncService;
use App\Models\Tenant\SyncJob;
use Illuminate\Http\{Request, JsonResponse};
use App\Support\Settings;

class SyncController extends BaseApiController {

    public function __construct(private SyncService $svc) {}

    public function syncProducts(Request $req): JsonResponse {
        // $request->validate()'s returned array only ever contains keys that
        // have an explicit rule — any field the plugin sends without a rule
        // here (sku, price, description, ...) is silently dropped before it
        // ever reaches SyncService::syncProducts(), even though that method
        // reads all of these. Every field it actually reads must be listed
        // below (as 'nullable' where not required), or it's discarded.
        $d = $req->validate([
            'chatbot_id'                    => 'required|uuid',
            'products'                      => 'required|array|min:1|max:' . $this->itemCap(),
            'products.*.id'                 => 'required|integer',
            'products.*.name'               => 'required|string',
            'products.*.sku'                => 'nullable|string',
            'products.*.type'               => 'nullable|string',
            'products.*.status'             => 'nullable|string',
            'products.*.description'        => 'nullable|string',
            'products.*.short_description'  => 'nullable|string',
            'products.*.price'              => 'nullable',
            'products.*.regular_price'      => 'nullable',
            'products.*.sale_price'         => 'nullable',
            'products.*.currency'           => 'nullable|string',
            'products.*.stock_status'       => 'nullable|string',
            'products.*.stock_quantity'     => 'nullable',
            'products.*.average_rating'     => 'nullable',
            'products.*.review_count'       => 'nullable',
            'products.*.permalink'          => 'nullable|string',
            'products.*.featured_image'     => 'nullable|string',
            'products.*.categories'         => 'nullable|array',
            'products.*.categories.*.name'  => 'nullable|string',
            'products.*.tags'               => 'nullable|array',
            'products.*.attributes'         => 'nullable|array|max:30',
            'products.*.attributes.*.name'  => 'nullable|string|max:200',
            'products.*.attributes.*.label' => 'nullable|string|max:100',
            'products.*.attributes.*.values'   => 'nullable|array|max:50',
            'products.*.attributes.*.values.*' => 'nullable|string|max:200',
            // Variable products (plugin 2.8.0+): each option's attributes,
            // price and stock, so "do you have it in size M?" has an answer.
            'products.*.variations'                => 'nullable|array|max:100',
            'products.*.variations.*.attributes'   => 'nullable|array|max:10',
            'products.*.variations.*.attributes.*' => 'nullable|string|max:200',
            'products.*.variations.*.price'        => 'nullable',
            'products.*.variations.*.stock_status' => 'nullable|string|max:20',
            'products.*.variations.*.sku'          => 'nullable|string|max:100',
            'products.*.weight'             => 'nullable|string|max:50',
            'products.*.dimensions'         => 'nullable|string|max:100',
            'products.*.attachments'        => 'nullable|array|max:20',
            'products.*.attachments.*.url'  => 'required_with:products.*.attachments|string',
            'products.*.attachments.*.name' => 'nullable|string',
            // "Is this genuine?" fields — see Haman_Product_Sync::
            // authenticity_fields() on the plugin side. Each is whatever the
            // seller actually recorded (via the admin-configured field
            // mapping) or absent entirely; never guessed here either.
            'products.*.authenticity_status'  => 'nullable|string|max:255',
            'products.*.brand'                => 'nullable|string|max:255',
            'products.*.official_distributor' => 'nullable|string|max:255',
            'products.*.warranty_period'      => 'nullable|string|max:255',
            'products.*.country_of_origin'    => 'nullable|string|max:255',
        ]);
        $t = app('current_tenant');
        return $this->accepted($this->jobArr($this->svc->syncProducts($d['chatbot_id'], $d['products'], $t->schema_name)));
    }

    /**
     * How many items one sync call may carry.
     *
     * products was capped at 50; pages and faqs were unbounded, so a single
     * request could hand over an arbitrarily long array and every item of it
     * gets embedded at the platform's expense. One setting now governs all
     * three, because the cost is the same cost.
     */
    private function itemCap(): int {
        return (int) Settings::get('limits.sync_items_per_request');
    }

    public function syncPages(Request $req): JsonResponse {
        $d = $req->validate([
            'chatbot_id'            => 'required|uuid',
            'pages'                 => 'required|array|min:1|max:' . $this->itemCap(),
            'pages.*.id'            => 'required|integer',
            'pages.*.title'         => 'required|string',
            'pages.*.content'       => 'nullable|string',
            'pages.*.url'           => 'nullable|string',
            'pages.*.post_type'     => 'nullable|string|max:20',
            'pages.*.excerpt'       => 'nullable|string',
            // Custom post types (plugin 2.6.0+). Every field SyncService::
            // postContentText() reads must have a rule here, or validate()
            // drops it before it gets there — see syncProducts() above.
            'pages.*.post_type_label'      => 'nullable|string|max:100',
            'pages.*.date'                 => 'nullable|string|max:40',
            'pages.*.author'               => 'nullable|string|max:255',
            'pages.*.taxonomies'           => 'nullable|array|max:20',
            'pages.*.taxonomies.*.taxonomy'=> 'nullable|string|max:64',
            'pages.*.taxonomies.*.label'   => 'nullable|string|max:100',
            'pages.*.taxonomies.*.terms'   => 'nullable|array|max:50',
            'pages.*.taxonomies.*.terms.*' => 'nullable|string|max:200',
            'pages.*.custom_fields'        => 'nullable|array|max:100',
            'pages.*.custom_fields.*'      => 'nullable',
        ]);
        $t = app('current_tenant');
        return $this->accepted($this->jobArr($this->svc->syncPages($d['chatbot_id'], $d['pages'], $t->schema_name)));
    }

    public function syncFaqs(Request $req): JsonResponse {
        // See the comment in syncProducts() above — same rule applies here:
        // SyncService::syncFaqs() also reads 'category', so it must be listed.
        $d = $req->validate([
            'chatbot_id'         => 'required|uuid',
            'faqs'               => 'required|array|min:1|max:' . $this->itemCap(),
            'faqs.*.question'    => 'required|string',
            'faqs.*.answer'      => 'required|string',
            'faqs.*.category'    => 'nullable|string',
        ]);
        $t = app('current_tenant');
        return $this->accepted($this->jobArr($this->svc->syncFaqs($d['chatbot_id'], $d['faqs'], $t->schema_name)));
    }

    public function handleWebhook(Request $req): JsonResponse {
        $d = $req->validate([
            'event'      => 'required|string',
            'chatbot_id' => 'required|uuid',
            'data'       => 'required|array',
        ]);
        // A delete archives a document by its WordPress ID — and a second site
        // sharing this chatbot has its own post 12. Only the chatbot's own
        // site may remove anything (SyncOrigin); create/update still apply.
        if (in_array($d['event'], ['page.deleted', 'product.deleted'], true)) {
            // TEMPORARY(SyncOrigin): remove once one API key = one chatbot = one domain.
            $origin = \App\Support\SyncOrigin::authorise($req, $d['chatbot_id'], 'delete');
            if (!$origin['allowed']) {
                return $this->ok(['job_id' => null, 'event' => $d['event'], 'skipped' => 'domain_mismatch', 'message' => $origin['message']]);
            }
        }
        $t = app('current_tenant');
        $j = $this->svc->processWebhook($d, $t->schema_name);
        return $this->ok(['job_id'=>$j?->id,'event'=>$d['event']]);
    }

    public function status(Request $req, string $id): JsonResponse {
        // A job id from another chatbot of the same tenant is not this key's business.
        $job = SyncJob::where('chatbot_id', $req->attributes->get('api_chatbot_id'))->findOrFail($id);
        return $this->ok($this->jobArr($job));
    }

    /**
     * "Clear and reindex" (SyncSettings' own docblock) — wipes this
     * chatbot's synced documents (products, pages, posts, PDF attachments;
     * never manually-entered FAQs) so a narrowed sync_settings scope
     * doesn't leave stale, now-excluded content sitting in the index. The
     * caller is expected to trigger a normal full sync immediately after —
     * this endpoint only clears, it never re-syncs on its own, since it has
     * no way to reach back into a specific WordPress site itself.
     */
    public function clearIndex(Request $req): JsonResponse {
        $d = $req->validate(['chatbot_id' => 'required|uuid']);
        // Deletes every synced document — only from the chatbot's own site (SyncOrigin).
        // TEMPORARY(SyncOrigin): remove once one API key = one chatbot = one domain.
        $origin = \App\Support\SyncOrigin::authorise($req, $d['chatbot_id'], 'clear');
        if (!$origin['allowed']) {
            return response()->json([
                'error' => $origin['message'],
                'reason' => 'domain_mismatch',
                'origin_domain' => $origin['origin'],
                'registered_domain' => $origin['registered'],
            ], 409);
        }
        $result = $this->svc->clearSyncedIndex($d['chatbot_id']);
        return $this->ok($result);
    }

    private function jobArr(SyncJob $j): array {
        return [
            'id'              => $j->id,
            'status'          => $j->status,
            'job_type'        => $j->job_type,
            'progress'        => $j->progressPercent(),
            'items_total'     => $j->items_total,
            'items_processed' => $j->items_processed,
            'completed_at'    => $j->completed_at?->toISOString(),
            // The new/updated/skipped/deleted/failed breakdown SyncService
            // already computes and saves on every job — this just hadn't
            // been added to the response the plugin actually reads. Without
            // it, the plugin's own sync_all() had nothing but a flat
            // "how many did I POST" count to report, so its admin results
            // table (settings-page.php, keyed on exactly these five names)
            // always read undefined keys and showed zero for every column,
            // regardless of what the sync actually did.
            'result'          => $j->result ?? [],
        ];
    }
}