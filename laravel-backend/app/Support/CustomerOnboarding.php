<?php
namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\{DB, Cache};

/**
 * Drives the customer portal's empty-state checklist (OnboardingChecklist
 * widget) vs. the real dashboard widgets — a brand-new tenant with zero data
 * should see four checkboxes to complete, not a wall of empty charts. Shared
 * here (rather than duplicated per-widget) since every customer dashboard
 * widget needs the same "is this tenant done onboarding?" gate to decide
 * canView().
 */
class CustomerOnboarding {
    public static function status(Tenant $tenant): array {
        return Cache::remember("onboarding_status:{$tenant->id}", 300, function () use ($tenant) {
            // Both public-schema existence checks in one query (two EXISTS
            // subqueries) instead of two — this gate runs on every customer
            // dashboard request that isn't cache-warm yet, and the dashboard
            // has a real query budget.
            //
            // "Plugin installed" can't be observed directly — the closest
            // real signal this app has is the tenant's API key actually
            // having been used at least once (the WordPress plugin
            // authenticating successfully), which happens before any sync
            // completes.
            $public = DB::selectOne('
                SELECT
                    EXISTS(SELECT 1 FROM chatbot_index WHERE tenant_id = ?) AS chatbot_created,
                    EXISTS(SELECT 1 FROM api_keys WHERE tenant_id = ? AND last_used_at IS NOT NULL) AS plugin_installed
            ', [$tenant->id, $tenant->id]);
            $chatbotCreated = (bool) $public->chatbot_created;
            $pluginInstalled = (bool) $public->plugin_installed;

            $firstSyncDone = false;
            $firstConversationDone = false;
            if ($chatbotCreated) {
                DB::statement("SET search_path TO {$tenant->schema_name}, public");
                $tenantChecks = DB::selectOne("
                    SELECT
                        EXISTS(SELECT 1 FROM sync_jobs WHERE status = 'completed') AS first_sync_done,
                        EXISTS(SELECT 1 FROM conversations) AS first_conversation_done
                ");
                $firstSyncDone = (bool) $tenantChecks->first_sync_done;
                $firstConversationDone = (bool) $tenantChecks->first_conversation_done;
                DB::statement('SET search_path TO public');
            }

            return [
                'chatbot_created'         => $chatbotCreated,
                'plugin_installed'        => $pluginInstalled,
                'first_sync_done'         => $firstSyncDone,
                'first_conversation_done' => $firstConversationDone,
                'complete'                => $chatbotCreated && $pluginInstalled && $firstSyncDone && $firstConversationDone,
            ];
        });
    }

    public static function isComplete(Tenant $tenant): bool {
        return self::status($tenant)['complete'];
    }
}
