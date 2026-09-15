<?php namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;

class VerifyWebhookSignature {
    public function handle(Request $request, Closure $next): mixed {
        // A 1.x plugin signs with the old header name. The signature
        // itself never changed, only what it is called.
        $sig    = $request->header('X-Haman-Signature')
                  ?: $request->header(\App\Support\PluginLegacy::SIGNATURE_HEADER);
        $tenant = app('current_tenant');
        $secret = $tenant?->getWebhookSecret();
        if (!$sig || !$secret) return response()->json(['error'=>'Missing signature'],401);
        $expected = 'sha256='.hash_hmac('sha256',$request->getContent(),$secret);
        if (!hash_equals($expected,$sig)) return response()->json(['error'=>'Invalid signature'],403);
        return $next($request);
    }
}
