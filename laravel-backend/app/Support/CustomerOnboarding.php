<?php
namespace App\Support;

use App\Models\Tenant;
use App\Models\ApiKey;
use App\Models\ChatbotIndexEntry;
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
            $chatbotCreated = ChatbotIndexEntry::where('tenant_id', $tenant->id)->exists();

            // "Plugin installed" can't be observed directly — the closest
            // real signal this app has is the tenant's API key actually
            // having been used at least once (the WordPress plugin
            // authenticating successfully), which happens before any sync
            // completes.
            $pluginInstalled = ApiKey::where('tenant_id', $tenant->id)->whereNotNull('last_used_at')->exists();

            $firstSyncDone = false;
            $firstConversationDone = false;
            if ($chatbotCreated) {
                DB::statement("SET search_path TO {$tenant->schema_name}, public");
                $firstSyncDone = DB::table('sync_jobs')->where('status', 'completed')->exists();
                $firstConversationDone = DB::table('conversations')->exists();
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
