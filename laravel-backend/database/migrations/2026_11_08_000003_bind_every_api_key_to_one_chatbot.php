<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{DB, Log};

/**
 * One API key = one chatbot. From this release AuthenticateTenantApiKey
 * refuses a key that is not bound to a chatbot (it could act on every
 * chatbot of its tenant), so existing unbound keys are bound here where the
 * answer is unambiguous: the tenant has exactly one chatbot. A tenant with
 * several chatbots (or none) keeps its unbound key unbound — refused, with
 * a message telling the site owner to copy their chatbot's own key — and is
 * listed in the application log for support to follow up.
 *
 * What this touches, read-only, BEFORE deploying:
 *
 *   SELECT k.id, k.name, k.key_prefix, k.last_used_at, t.name AS tenant, t.email,
 *          (SELECT count(*) FROM chatbot_index ci WHERE ci.tenant_id = k.tenant_id) AS chatbots
 *   FROM api_keys k JOIN tenants t ON t.id = k.tenant_id
 *   WHERE k.is_active AND k.chatbot_id IS NULL
 *   ORDER BY k.last_used_at DESC NULLS LAST;
 *
 *   rows with chatbots = 1 → bound automatically; any other count → refused.
 *
 * down() keeps the bindings: a bound key works with the previous code too,
 * so a rollback has nothing to undo here.
 */
return new class extends Migration {
    public function up(): void {
        $unbound = DB::table('api_keys')->where('is_active', true)->whereNull('chatbot_id')->get(['id', 'tenant_id', 'key_prefix']);

        $bound = [];
        $left = [];
        foreach ($unbound as $key) {
            $chatbots = DB::table('chatbot_index')->where('tenant_id', $key->tenant_id)->pluck('chatbot_id');
            if ($chatbots->count() === 1) {
                DB::table('api_keys')->where('id', $key->id)->update(['chatbot_id' => $chatbots->first()]);
                $bound[] = $key->key_prefix;
            } else {
                $left[] = ['key_prefix' => $key->key_prefix, 'tenant_id' => $key->tenant_id, 'chatbots' => $chatbots->count()];
            }
        }

        Log::warning('one-key-one-chatbot: API keys bound / left unbound (refused)', ['bound' => $bound, 'left_unbound' => $left]);
    }

    public function down(): void {
        // Nothing reliable to undo without the log; bindings are kept.
    }
};
