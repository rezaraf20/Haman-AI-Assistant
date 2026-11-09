<?php namespace App\Http\Controllers\Api\V1;
use App\Models\Tenant\{Chatbot, Document, Product};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\DB;
use App\Support\DomainNormalizer;
use App\Support\WidgetDefaults;

class ChatbotController extends BaseApiController {

    public function index(Request $req): JsonResponse {
        // Through a plugin key, only the key's own chatbot (one key = one chatbot).
        $q = Chatbot::orderBy('created_at','desc');
        if ($bound = $req->attributes->get('api_chatbot_id')) $q->where('id', $bound);
        return $this->ok($q->get());
    }

    public function store(Request $req): JsonResponse {
        $d      = $req->validate(['name'=>'required|string|max:255','type'=>'required|in:support,sales,faq,woocommerce,hr,custom','welcome_message'=>'nullable|string|max:2000','fallback_response'=>'nullable|string|max:2000','system_prompt'=>'nullable|string|max:10000','language'=>'nullable|string|max:10','response_language'=>'nullable|string|max:10','widget_config'=>'nullable|array',
            // One chatbot = one domain, required up front: a chatbot without
            // one would sit "awaiting connection" and answer nobody.
            'domain'=>'required|string|max:255']);
        $domain = DomainNormalizer::normalize($d['domain']);
        if (!DomainNormalizer::isValid($domain)) {
            return response()->json(['error' => __('chatbot_domain.invalid', [], 'en'), 'reason' => 'invalid_domain'], 422);
        }
        unset($d['domain']);
        $tenant = app('current_tenant');
        $max    = $tenant->plan->max_chatbots ?? 1;
        if (Chatbot::count() >= $max) return $this->forbidden("Plan limit: max {$max} chatbot(s).");
        $chatbot = Chatbot::create(array_merge($d,['status'=>'active','embedding_model'=>'models/text-embedding-004','llm_model'=>'gemini-1.5-flash','temperature'=>0.3,'retrieval_top_k'=>8,'retrieval_threshold'=>0.60,'memory_window'=>6,'is_active'=>true]));
        // Register in public schema index
        DB::statement("SET search_path TO public");
        DB::table('chatbot_index')->upsert(
            ['chatbot_id'=>$chatbot->id,'tenant_id'=>$tenant->id,'schema_name'=>$tenant->schema_name,'is_active'=>true,'name'=>$chatbot->name,'primary_domain'=>$domain],
            ['chatbot_id'],['schema_name','is_active','name']
        );
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        return $this->created($chatbot);
    }

    public function show(string $id): JsonResponse {
        $chatbot = Chatbot::with(['domains'])->findOrFail($id);
        // widget_config_merged: the complete, always-defaulted config (see
        // WidgetDefaults::merge()) — what the WordPress plugin's read-only
        // display and the customer portal's WidgetSettings page both read,
        // as opposed to the raw (possibly null/unset) widget_config column
        // above it, which is kept for backward compatibility with any
        // existing caller of this endpoint.
        return $this->ok(array_merge($chatbot->toArray(), [
            'widget_config_merged' => WidgetDefaults::merge($chatbot),
        ]));
    }

    public function update(Request $req, string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
        $d = $req->validate(['name'=>'sometimes|string','welcome_message'=>'nullable|string','fallback_response'=>'nullable|string','system_prompt'=>'nullable|string','widget_config'=>'nullable|array','temperature'=>'nullable|numeric','retrieval_top_k'=>'nullable|integer','retrieval_threshold'=>'nullable|numeric','memory_window'=>'nullable|integer','is_active'=>'nullable|boolean','language'=>'nullable|string','response_language'=>'nullable|string']);
        $chatbot->update($d);
        return $this->ok($chatbot->fresh());
    }

    public function updateWidgetSettings(Request $req, string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
        // Every field this method's caller (the WordPress plugin's settings
        // page) can send must be listed here with a rule — $request->
        // validate() silently drops anything that isn't (see SyncController
        // ::syncProducts()'s identical fix for the real incident this
        // caused there).
        $d = $req->validate([
            'auto_reply_enabled'        => 'sometimes|boolean',
            'ai_name'                   => 'sometimes|nullable|string|max:100',
            'system_instruction'        => 'sometimes|nullable|string|max:10000',
            'chat_title'                => 'sometimes|nullable|string|max:150',
            'welcome_text'              => 'sometimes|nullable|string|max:2000',
            'input_placeholder'         => 'sometimes|nullable|string|max:150',
            'rate_limit_max_messages'   => 'sometimes|nullable|integer|min:1|max:100000',
            'rate_limit_block_minutes'  => 'sometimes|nullable|integer|min:1|max:10080',
            'quick_questions'           => 'sometimes|array|max:200',
            'quick_questions.*.question'=> 'required_with:quick_questions|string|max:300',
            'quick_questions.*.answer'  => 'required_with:quick_questions|string|max:3000',
            'fallback_response'         => 'sometimes|nullable|string|max:2000',
            'primary_color'             => 'sometimes|nullable|string|max:20',
            'position'                  => 'sometimes|nullable|in:bottom-left,bottom-right',
            'avatar_url'                => 'sometimes|nullable|string|max:1000',
            'lead_capture_enabled'      => 'sometimes|boolean',
            'lead_capture_prompt'       => 'sometimes|nullable|string|max:1000',
            'lead_capture_thanks'       => 'sometimes|nullable|string|max:1000',
            'lead_capture_invalid'      => 'sometimes|nullable|string|max:1000',
        ]);

        $widgetConfig = array_merge($chatbot->widget_config ?? [], array_filter([
            'ai_name'                  => $d['ai_name'] ?? null,
            'chat_title'                => $d['chat_title'] ?? null,
            'input_placeholder'         => $d['input_placeholder'] ?? null,
            'rate_limit_max_messages'   => $d['rate_limit_max_messages'] ?? null,
            'rate_limit_block_minutes'  => $d['rate_limit_block_minutes'] ?? null,
            'primary_color'             => $d['primary_color'] ?? null,
            'position'                  => $d['position'] ?? null,
            'avatar_url'                => $d['avatar_url'] ?? null,
            'lead_capture_prompt'       => $d['lead_capture_prompt'] ?? null,
            'lead_capture_thanks'       => $d['lead_capture_thanks'] ?? null,
            'lead_capture_invalid'      => $d['lead_capture_invalid'] ?? null,
        ], fn($v) => $v !== null));
        if (array_key_exists('quick_questions', $d)) {
            $widgetConfig['quick_questions'] = $d['quick_questions'];
        }
        if (array_key_exists('lead_capture_enabled', $d)) {
            $widgetConfig['lead_capture_enabled'] = $d['lead_capture_enabled'];
        }

        $update = ['widget_config' => $widgetConfig];
        if (array_key_exists('auto_reply_enabled', $d)) $update['is_active'] = $d['auto_reply_enabled'];
        if (array_key_exists('system_instruction', $d)) $update['system_prompt'] = $d['system_instruction'];
        if (array_key_exists('welcome_text', $d))        $update['welcome_message'] = $d['welcome_text'];
        if (array_key_exists('fallback_response', $d))   $update['fallback_response'] = $d['fallback_response'];

        $chatbot->update($update);
        return $this->ok($chatbot->fresh());
    }

    /**
     * The portal is authoritative for this setting (see App\Support\
     * SyncSettings's own docblock) — the plugin calls this before every
     * sync run so a change made from the portal reaches WordPress without
     * requiring a WordPress-side admin action first.
     */
    public function syncSettings(string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
        return $this->ok(\App\Support\SyncSettings::forApi(\App\Support\SyncSettings::merge($chatbot))
            + ['limits' => \App\Support\SyncLimits::forApi()]);
    }

    /**
     * The plugin's own settings page calls this on save, so a change made
     * from WordPress reaches the portal too — the same "whichever side the
     * admin actually used, both end up agreeing" shape updateWidgetSettings()
     * above already established for appearance/text settings.
     */
    public function updateSyncSettings(Request $req, string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
        $d = $req->validate([
            'sync_products'           => 'sometimes|boolean',
            // Version 1 (plugin < 2.6.0) — see SyncService::applySyncSettings()
            // for how these are folded into post_types without disturbing
            // custom types the portal enabled.
            'sync_pages'              => 'sometimes|boolean',
            'sync_posts'              => 'sometimes|boolean',
            'post_types'              => 'sometimes|array|max:50',
            'post_types.*'            => 'string|max:20',
            'custom_fields'           => 'sometimes|array|max:50',
            'custom_fields.*'         => 'array|max:100',
            'custom_fields.*.*'       => 'string|max:191',
            'available_post_types'    => 'sometimes|array|max:100',
            // WooCommerce attributes: the owner's choice (null = the plugin's
            // default, what the product page shows) and what the plugin found.
            'product_attributes'      => 'sometimes|nullable|array|max:100',
            'product_attributes.*'    => 'string|max:200',
            'available_product_attributes'           => 'sometimes|array|max:100',
            'available_product_attributes.*.label'   => 'nullable|string|max:100',
            'available_product_attributes.*.count'   => 'nullable|integer|min:0',
            'available_product_attributes.*.visible' => 'nullable|boolean',
            'available_product_attributes.*.samples' => 'nullable|array|max:6',
            'available_product_attributes.*.samples.*' => 'nullable|string|max:200',
            'available_post_types.*.label' => 'nullable|string|max:100',
            'available_post_types.*.count' => 'nullable|integer|min:0',
            'excluded_category_ids'   => 'sometimes|array',
            'excluded_category_ids.*' => 'integer',
            'excluded_page_ids'       => 'sometimes|array',
            'excluded_page_ids.*'     => 'integer',
        ]);

        // Only the chatbot's own site may overwrite its settings — saving
        // them archives every type that is "off", which on a second site
        // sharing this chatbot means the first site's content. See SyncOrigin.
        // TEMPORARY(SyncOrigin): remove once one API key = one chatbot = one domain.
        $origin = \App\Support\SyncOrigin::authorise($req, $chatbot->id, 'settings');
        if (!$origin['allowed']) {
            return response()->json([
                'error' => $origin['message'],
                'reason' => 'domain_mismatch',
                'origin_domain' => $origin['origin'],
                'registered_domain' => $origin['registered'],
            ], 409);
        }

        $result = app(\App\Services\SyncService::class)->applySyncSettings($chatbot, $d);

        return $this->ok(\App\Support\SyncSettings::forApi($result['settings']) + [
            'archived_documents' => $result['archived_documents'],
            'limits' => \App\Support\SyncLimits::forApi(),
        ]);
    }

    /** See App\Support\KnowledgeSummary — the same numbers the portal's Knowledge page shows. */
    public function knowledge(string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
        return $this->ok(\App\Support\KnowledgeSummary::forChatbot($chatbot->id, app('current_tenant')));
    }

    public function destroy(string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
        $chatbot->update(['is_active'=>false,'status'=>'inactive']);
        DB::statement("SET search_path TO public");
        DB::table('chatbot_index')->where('chatbot_id',$id)->update(['is_active'=>false]);
        return $this->noContent();
    }

    /**
     * Multi-domain is not supported (one chatbot = one domain): the domain is
     * set when the chatbot is created, or registered once in the portal, and
     * changed only by support. These two endpoints used to write the
     * chatbot_domains table that nothing reads, and "first domain wins" into
     * primary_domain; they now say so instead.
     */
    public function addDomain(Request $req, string $id): JsonResponse {
        return response()->json(['error' => __('chatbot_domain.multi_domain_unsupported', [], 'en'), 'reason' => 'multi_domain_unsupported'], 410);
    }

    public function removeDomain(string $id, string $domain): JsonResponse {
        return response()->json(['error' => __('chatbot_domain.multi_domain_unsupported', [], 'en'), 'reason' => 'multi_domain_unsupported'], 410);
    }

    public function documents(string $id): JsonResponse {
        return $this->ok(Document::where('chatbot_id',$id)->orderBy('created_at','desc')->paginate(20));
    }

    public function products(string $id): JsonResponse {
        return $this->ok(Product::where('chatbot_id',$id)->paginate(20));
    }

    public function stats(string $id): JsonResponse {
        $c = Chatbot::findOrFail($id);
        return $this->ok(['total_conversations'=>$c->total_conversations,'total_messages'=>$c->total_messages,'documents'=>Document::where('chatbot_id',$id)->where('status','indexed')->count(),'products'=>Product::where('chatbot_id',$id)->count()]);
    }
}
