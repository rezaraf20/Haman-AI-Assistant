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
        // The key carries a version because this array is cached for five
        // minutes in a store that outlives a deploy: without it, a release
        // that adds a key to the array is read back by the new code from an
        // entry the old code wrote, which is missing it.
        return Cache::remember("onboarding_status:v2:{$tenant->id}", 300, function () use ($tenant) {
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
            $anyToolEnabled = false;
            if ($chatbotCreated) {
                try {
                    DB::statement("SET search_path TO {$tenant->schema_name}, public");
                    // enabled_tools rides along in this same statement rather
                    // than in a query of its own: the setup guide's navigation
                    // badge asks for it on every portal page, and a separate
                    // read would also need its own pair of search_path
                    // statements, which is three queries against the
                    // dashboard's budget for one boolean.
                    $tenantChecks = DB::selectOne("
                        SELECT
                            EXISTS(SELECT 1 FROM sync_jobs WHERE status = 'completed') AS first_sync_done,
                            EXISTS(SELECT 1 FROM conversations) AS first_conversation_done,
                            (SELECT COALESCE(json_agg(enabled_tools), '[]'::json) FROM chatbots) AS enabled_tools_sets
                    ");
                    $firstSyncDone = (bool) $tenantChecks->first_sync_done;
                    $firstConversationDone = (bool) $tenantChecks->first_conversation_done;
                    $anyToolEnabled = self::anyToolEnabled($tenantChecks->enabled_tools_sets);
                } catch (\Throwable $e) {
                    // An incomplete tenant schema (missing sync_jobs/
                    // conversations) must not 500 the whole dashboard — see
                    // FailedSyncsTable's identical fix for the real incident
                    // this traces back to. Falls back to "not done yet",
                    // which just shows the onboarding checklist.
                    \Illuminate\Support\Facades\Log::warning("CustomerOnboarding: tenant {$tenant->id} ({$tenant->schema_name}) — {$e->getMessage()}");
                } finally {
                    DB::statement('SET search_path TO public');
                }
            }

            return [
                'chatbot_created'         => $chatbotCreated,
                'plugin_installed'        => $pluginInstalled,
                'first_sync_done'         => $firstSyncDone,
                'first_conversation_done' => $firstConversationDone,
                'any_tool_enabled'        => $anyToolEnabled,
                'complete'                => $chatbotCreated && $pluginInstalled && $firstSyncDone && $firstConversationDone,
            ];
        });
    }

    /**
     * Whether any chatbot has at least one tool switched on.
     *
     * The names are sanitised in PHP rather than filtered in SQL so this
     * agrees with ChatbotTools exactly: a row naming only tools the platform
     * no longer offers counts as nothing enabled.
     *
     * @param string|null $json json_agg of every chatbot's enabled_tools
     */
    private static function anyToolEnabled(?string $json): bool {
        foreach (json_decode((string) $json, true) ?: [] as $value) {
            $tools = is_string($value) ? (json_decode($value, true) ?: []) : (array) $value;
            if (ChatbotTools::sanitise($tools)) return true;
        }

        return false;
    }

    public static function isComplete(Tenant $tenant): bool {
        return self::status($tenant)['complete'];
    }
}
