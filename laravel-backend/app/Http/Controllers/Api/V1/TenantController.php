<?php namespace App\Http\Controllers\Api\V1;
use App\Models\ApiKey;
use Illuminate\Http\{Request,JsonResponse};
use Illuminate\Support\Str;

class TenantController extends BaseApiController {
    // API-key-authenticated (WordPress plugin) counterparts to show()/
    // update() above, which are Sanctum-session-authenticated (customer
    // portal) — different auth mechanism, so $req->user()->tenant isn't
    // available here; AuthenticateTenantApiKey sets app('current_tenant')
    // instead. See routes/api.php's 'auth.apikey' group vs 'auth:sanctum'.
    public function webhookSecret(): JsonResponse {
        $t = app('current_tenant');
        $secret = $t->getWebhookSecret();
        if (!$secret) {
            $secret = $this->generateAndStoreWebhookSecret($t);
        }
        return $this->ok(['webhook_secret' => $secret]);
    }

    public function regenerateWebhookSecret(): JsonResponse {
        $t = app('current_tenant');
        return $this->ok(['webhook_secret' => $this->generateAndStoreWebhookSecret($t)]);
    }

    private function generateAndStoreWebhookSecret($tenant): string {
        $secret = Str::random(40);
        $tenant->update(['settings' => array_merge($tenant->settings ?? [], ['webhook_secret' => $secret])]);
        return $secret;
    }
    public function show(Request $req): JsonResponse {
        $t = $req->user()->tenant->load('plan','subscription');
        return $this->ok(['id'=>$t->id,'name'=>$t->name,'email'=>$t->email,'status'=>$t->status,'plan'=>$t->plan?->only(['name','slug','features','max_chatbots','max_tokens_monthly']),'usage'=>['tokens'=>$t->usage_tokens_current,'messages'=>$t->usage_messages_current]]);
    }
    public function update(Request $req): JsonResponse {
        $t = $req->user()->tenant;
        $t->update($req->validate(['name'=>'sometimes|string','timezone'=>'nullable|string','language'=>'nullable|string']));
        return $this->ok($t->fresh());
    }
    public function usage(Request $req): JsonResponse {
        $t = $req->user()->tenant->load('plan');
        return $this->ok(['tokens'=>['used'=>$t->usage_tokens_current,'limit'=>$t->plan?->max_tokens_monthly],'messages'=>['used'=>$t->usage_messages_current,'limit'=>$t->plan?->max_messages_monthly],'resets_at'=>now()->endOfMonth()->toISOString()]);
    }
    public function apiKeys(Request $req): JsonResponse {
        return $this->ok(ApiKey::where('tenant_id',$req->user()->tenant_id)->where('is_active',true)->get(['id','name','key_prefix','scopes','last_used_at','created_at']));
    }
    public function createApiKey(Request $req): JsonResponse {
        $d = $req->validate(['name'=>'required|string|max:255']);
        [, $raw] = ApiKey::generate($req->user()->tenant_id, null, $d['name'], $req->user()->id);
        return $this->created(['key'=>$raw,'note'=>'Store this key — shown once only']);
    }
    public function revokeApiKey(Request $req, string $id): JsonResponse {
        ApiKey::where('tenant_id',$req->user()->tenant_id)->where('id',$id)->update(['is_active'=>false]);
        return $this->noContent();
    }
}
