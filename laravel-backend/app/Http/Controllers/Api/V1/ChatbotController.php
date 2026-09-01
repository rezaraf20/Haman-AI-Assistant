<?php namespace App\Http\Controllers\Api\V1;
use App\Models\Tenant\{Chatbot, ChatbotDomain, Document, Product};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\DB;
use App\Support\DomainNormalizer;

class ChatbotController extends BaseApiController {

    public function index(): JsonResponse {
        return $this->ok(Chatbot::orderBy('created_at','desc')->get());
    }

    public function store(Request $req): JsonResponse {
        $d      = $req->validate(['name'=>'required|string|max:255','type'=>'required|in:support,sales,faq,woocommerce,hr,custom','welcome_message'=>'nullable|string|max:2000','fallback_response'=>'nullable|string|max:2000','system_prompt'=>'nullable|string|max:10000','language'=>'nullable|string|max:10','response_language'=>'nullable|string|max:10','widget_config'=>'nullable|array']);
        $tenant = app('current_tenant');
        $max    = $tenant->plan->max_chatbots ?? 1;
        if (Chatbot::count() >= $max) return $this->forbidden("Plan limit: max {$max} chatbot(s).");
        $chatbot = Chatbot::create(array_merge($d,['status'=>'active','embedding_model'=>'models/text-embedding-004','llm_model'=>'gemini-1.5-flash','temperature'=>0.3,'retrieval_top_k'=>8,'retrieval_threshold'=>0.60,'memory_window'=>6,'is_active'=>true]));
        // Register in public schema index
        DB::statement("SET search_path TO public");
        DB::table('chatbot_index')->upsert(
            ['chatbot_id'=>$chatbot->id,'tenant_id'=>$tenant->id,'schema_name'=>$tenant->schema_name,'is_active'=>true,'name'=>$chatbot->name],
            ['chatbot_id'],['schema_name','is_active','name']
        );
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        return $this->created($chatbot);
    }

    public function show(string $id): JsonResponse {
        return $this->ok(Chatbot::with(['domains'])->findOrFail($id));
    }

    public function update(Request $req, string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
        $d = $req->validate(['name'=>'sometimes|string','welcome_message'=>'nullable|string','fallback_response'=>'nullable|string','system_prompt'=>'nullable|string','widget_config'=>'nullable|array','temperature'=>'nullable|numeric','retrieval_top_k'=>'nullable|integer','retrieval_threshold'=>'nullable|numeric','memory_window'=>'nullable|integer','is_active'=>'nullable|boolean','language'=>'nullable|string','response_language'=>'nullable|string']);
        $chatbot->update($d);
        return $this->ok($chatbot->fresh());
    }

    public function updateWidgetSettings(Request $req, string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
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
        ]);

        $widgetConfig = array_merge($chatbot->widget_config ?? [], array_filter([
            'ai_name'                  => $d['ai_name'] ?? null,
            'chat_title'                => $d['chat_title'] ?? null,
            'input_placeholder'         => $d['input_placeholder'] ?? null,
            'rate_limit_max_messages'   => $d['rate_limit_max_messages'] ?? null,
            'rate_limit_block_minutes'  => $d['rate_limit_block_minutes'] ?? null,
        ], fn($v) => $v !== null));
        if (array_key_exists('quick_questions', $d)) {
            $widgetConfig['quick_questions'] = $d['quick_questions'];
        }

        $update = ['widget_config' => $widgetConfig];
        if (array_key_exists('auto_reply_enabled', $d)) $update['is_active'] = $d['auto_reply_enabled'];
        if (array_key_exists('system_instruction', $d)) $update['system_prompt'] = $d['system_instruction'];
        if (array_key_exists('welcome_text', $d))        $update['welcome_message'] = $d['welcome_text'];

        $chatbot->update($update);
        return $this->ok($chatbot->fresh());
    }

    public function destroy(string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
        $chatbot->update(['is_active'=>false,'status'=>'inactive']);
        DB::statement("SET search_path TO public");
        DB::table('chatbot_index')->where('chatbot_id',$id)->update(['is_active'=>false]);
        return $this->noContent();
    }

    public function addDomain(Request $req, string $id): JsonResponse {
        $d      = $req->validate(['domain'=>'required|string|max:255']);
        $domain = DomainNormalizer::normalize($d['domain']);
        if (!$domain) return $this->badRequest('Invalid domain');
        $result = ChatbotDomain::firstOrCreate(['chatbot_id'=>$id,'domain'=>$domain],['is_active'=>true,'created_at'=>now()]);
        // Best-effort: keep the admin panel's display column in sync. Only set it if
        // this chatbot doesn't already have a primary_domain (first domain added wins).
        // Nothing later in this request depends on the tenant schema still being active.
        DB::statement("SET search_path TO public");
        DB::table('chatbot_index')->where('chatbot_id',$id)->whereNull('primary_domain')->update(['primary_domain'=>$domain]);
        return $this->created($result);
    }

    public function removeDomain(string $id, string $domain): JsonResponse {
        ChatbotDomain::where('chatbot_id',$id)->where('domain',$domain)->delete();
        return $this->noContent();
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
