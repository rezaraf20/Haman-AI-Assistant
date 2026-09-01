<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Models\ApiKey;
use App\Models\ChatbotIndexEntry;

class AuthenticateTenantApiKey {
    public function handle(Request $request, Closure $next): mixed {
        $raw = $request->bearerToken();
        if (empty($raw) || !str_starts_with($raw, 'hfp_')) {
            return response()->json(['error' => 'Missing API key'], 401);
        }
        $prefix = substr($raw, 0, 12);
        $candidates = ApiKey::where('key_prefix', $prefix)
            ->where('is_active', true)
            ->with('tenant.plan')
            ->get();
        $apiKey = null;
        foreach ($candidates as $k) {
            if (password_verify($raw, $k->key_hash)) {
                $apiKey = $k;
                break;
            }
        }
        if (empty($apiKey)) return response()->json(['error' => 'Invalid API key'], 401);
        if (empty($apiKey->tenant)) return response()->json(['error' => 'Account error'], 403);
        if ($apiKey->isExpired()) return response()->json(['error' => 'API key expired'], 401);
        if ($apiKey->chatbot_id) {
            $entry = ChatbotIndexEntry::find($apiKey->chatbot_id);
            if (!$entry || !$entry->is_active) {
                return response()->json(['error' => 'Chatbot suspended'], 403);
            }
        }
        app()->instance('current_tenant', $apiKey->tenant);
        return $next($request);
    }
}