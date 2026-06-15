<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;
use App\Models\Tenant\ChatbotDomain;
class ValidateChatbotDomain {
    public function handle(Request $request, Closure $next): mixed {
        $chatbotId = $request->input('chatbot_id') ?? $request->route('chatbotId');
        $origin    = parse_url($request->header('Origin','')??'', PHP_URL_HOST) ?? '';
        if($chatbotId && $origin) {
            $allowed = ChatbotDomain::where('chatbot_id',$chatbotId)->where('domain',$origin)->where('is_active',true)->exists();
            if(!$allowed) return response()->json(['error'=>'Domain not authorized'],403);
        }
        return $next($request);
    }
}
