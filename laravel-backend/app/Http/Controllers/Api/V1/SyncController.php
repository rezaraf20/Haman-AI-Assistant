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
            'products.*.attributes'         => 'nullable|array',
            'products.*.attachments'        => 'nullable|array|max:20',
            'products.*.attachments.*.url'  => 'required_with:products.*.attachments|string',
            'products.*.attachments.*.name' => 'nullable|string',
            // "Is this genuine?" fields — see Hamman_Product_Sync::
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
            'pages.*.post_type'     => 'nullable|string',
            'pages.*.excerpt'       => 'nullable|string',
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
        $t = app('current_tenant');
        $j = $this->svc->processWebhook($d, $t->schema_name);
        return $this->ok(['job_id'=>$j?->id,'event'=>$d['event']]);
    }

    public function status(string $id): JsonResponse {
        return $this->ok($this->jobArr(SyncJob::findOrFail($id)));
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
        ];
    }
}