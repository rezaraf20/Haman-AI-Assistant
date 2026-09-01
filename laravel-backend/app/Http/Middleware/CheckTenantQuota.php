<?php namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;

class CheckTenantQuota {
    public function handle(Request $request, Closure $next): mixed {
        $t = app('current_tenant');
        if ($t && $t->isTokenQuotaExceeded()) {
            return response()->json(['error'=>'Token quota exceeded','resets'=>now()->endOfMonth()->toISOString()],429);
        }
        return $next($request);
    }
}
