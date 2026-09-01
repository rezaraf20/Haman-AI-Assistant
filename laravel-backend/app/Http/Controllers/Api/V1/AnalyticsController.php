<?php namespace App\Http\Controllers\Api\V1;
use App\Models\Tenant\{Conversation,Message};
use Illuminate\Http\{Request,JsonResponse};

class AnalyticsController extends BaseApiController {
    public function dashboard(Request $req): JsonResponse {
        $d    = $req->validate(['chatbot_id'=>'nullable|uuid','days'=>'nullable|integer|min:1|max:90']);
        $days = $d['days'] ?? 30;
        $from = now()->subDays($days)->toDateString();
        $convs = Conversation::when($d['chatbot_id']??null,fn($q,$id)=>$q->where('chatbot_id',$id))->where('started_at','>=',$from)->count();
        $msgs  = Message::when($d['chatbot_id']??null,fn($q,$id)=>$q->where('chatbot_id',$id))->where('created_at','>=',$from)->count();
        $tokens= Message::when($d['chatbot_id']??null,fn($q,$id)=>$q->where('chatbot_id',$id))->where('created_at','>=',$from)->sum('total_tokens');
        return $this->ok(['period_days'=>$days,'total_conversations'=>$convs,'total_messages'=>$msgs,'total_tokens'=>$tokens]);
    }
    public function conversations(Request $req): JsonResponse {
        $d = $req->validate(['chatbot_id'=>'nullable|uuid','status'=>'nullable|string']);
        return $this->ok(Conversation::when($d['chatbot_id']??null,fn($q,$id)=>$q->where('chatbot_id',$id))->when($d['status']??null,fn($q,$s)=>$q->where('status',$s))->orderBy('started_at','desc')->paginate(20));
    }
    public function tokenUsage(Request $req): JsonResponse {
        $tenant = $req->user()->tenant->load('plan');
        return $this->ok(['current_month'=>$tenant->usage_tokens_current,'limit'=>$tenant->plan?->max_tokens_monthly]);
    }
}
