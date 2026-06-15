<?php namespace App\Http\Controllers\Api\V1;
use App\Models\Tenant\{Chatbot, ChatbotDomain, Document, Product};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\DB;

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
        DB::table('chatbot_index')->upsert(['chatbot_id'=>$chatbot->id,'tenant_id'=>$tenant->id,'schema_name'=>$tenant->schema_name,'is_active'=>true],['chatbot_id'],['schema_name','is_active']);
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

    public function destroy(string $id): JsonResponse {
        $chatbot = Chatbot::findOrFail($id);
        $chatbot->update(['is_active'=>false,'status'=>'inactive']);
        DB::statement("SET search_path TO public");
        DB::table('chatbot_index')->where('chatbot_id',$id)->update(['is_active'=>false]);
        return $this->noContent();
    }

    public function addDomain(Request $req, string $id): JsonResponse {
        $d      = $req->validate(['domain'=>'required|string|max:255']);
        $domain = strtolower(trim(parse_url($d['domain'],PHP_URL_HOST)??$d['domain']));
        return $this->created(ChatbotDomain::firstOrCreate(['chatbot_id'=>$id,'domain'=>$domain],['is_active'=>true,'created_at'=>now()]));
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
