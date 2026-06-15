<?php namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\ApiKey;

class AuthenticateTenantApiKey {
    public function handle(Request $request, Closure $next): mixed {
        $raw = $request->bearerToken();
        if (!$raw || !str_starts_with($raw, 'hfp_')) {
            return response()->json(['error'=>'Missing API key'],401);
        }
        $prefix   = substr($raw,0,12);
        $cacheKey = 'apikey:'.$prefix;
        $apiKey   = Cache::remember($cacheKey, 300, function() use($prefix,$raw) {
            $candidates = ApiKey::where('key_prefix',$prefix)->where('is_active',true)->with('tenant.plan')->get();
            foreach ($candidates as $k) { if (password_verify($raw,$k->key_hash)) return $k; }
            return null;
        });
        if (!$apiKey) return response()->json(['error'=>'Invalid API key'],401);
        if ($apiKey->isExpired()) { Cache::forget($cacheKey); return response()->json(['error'=>'API key expired'],401); }
        if (!$apiKey->tenant||!$apiKey->tenant->isAccessible()) return response()->json(['error'=>'Account suspended'],403);
        app()->instance('current_tenant',$apiKey->tenant);
        dispatch(static fn()=>$apiKey->updateQuietly(['last_used_at'=>now(),'last_used_ip'=>$request->ip()]))->afterResponse();
        return $next($request);
    }
}
