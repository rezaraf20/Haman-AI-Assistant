<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SetTenantSchema {
    public function handle(Request $request, Closure $next): mixed {
        $tenant = null;

        // Try to get from container
        try {
            $tenant = app('current_tenant');
        } catch (\Throwable $e) {}

        // Try to get from authenticated user
        if (!$tenant && $request->user()) {
            $user = $request->user();
            $tenant = $user->tenant()->first();
            if ($tenant) {
                app()->instance('current_tenant', $tenant);
            }
        }

        // Try API key auth
        if (!$tenant) {
            $apiKey = $request->header('X-API-Key') ?? $request->header('Authorization');
            if ($apiKey) {
                $apiKey = str_replace('Bearer ', '', $apiKey);
                $key = \App\Models\ApiKey::where('key', $apiKey)->with('tenant')->first();
                if ($key && $key->tenant) {
                    $tenant = $key->tenant;
                    app()->instance('current_tenant', $tenant);
                }
            }
        }

        if ($tenant) {
            DB::statement("SET search_path TO {$tenant->schema_name}, public");
        }

        return $next($request);
    }
}