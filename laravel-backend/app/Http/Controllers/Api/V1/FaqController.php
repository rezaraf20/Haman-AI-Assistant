<?php namespace App\Http\Controllers\Api\V1;
use App\Models\Tenant\Faq;
use Illuminate\Http\{Request,JsonResponse};

class FaqController extends BaseApiController {
    public function index(string $chatbotId): JsonResponse {
        return $this->ok(Faq::where('chatbot_id',$chatbotId)->orderBy('sort_order')->paginate(50));
    }
    public function store(Request $req, string $chatbotId): JsonResponse {
        $d = $req->validate(['question'=>'required|string','answer'=>'required|string','category'=>'nullable|string']);
        $f = Faq::create(array_merge($d,['chatbot_id'=>$chatbotId,'source'=>'manual','is_active'=>true]));
        return $this->created($f);
    }
    public function update(Request $req, string $chatbotId, string $id): JsonResponse {
        $f = Faq::where('chatbot_id',$chatbotId)->where('id',$id)->firstOrFail();
        $f->update($req->validate(['question'=>'sometimes|string','answer'=>'sometimes|string','category'=>'nullable|string','is_active'=>'nullable|boolean']));
        return $this->ok($f->fresh());
    }
    public function destroy(string $chatbotId, string $id): JsonResponse {
        Faq::where('chatbot_id',$chatbotId)->where('id',$id)->firstOrFail()->delete();
        return $this->noContent();
    }
}
