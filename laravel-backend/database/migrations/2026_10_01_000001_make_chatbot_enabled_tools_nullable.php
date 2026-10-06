<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{DB, Log};

/**
 * enabled_tools used to be NOT NULL DEFAULT '[]' in every tenant schema, so
 * '[]' meant two different things with no way to tell them apart: "a
 * merchant explicitly unchecked everything" and "this chatbot has never had
 * its tools touched at all" — true for essentially every chatbot in
 * production, since nothing could even write to this column until Widget
 * Settings' tools section shipped (see ChatbotTools's own docblock: "every
 * chatbot in production sat at [] and no tool had ever run").
 *
 * Chatbot::effectiveTools() now treats NULL as "apply the plan's
 * default-enabled tools" (ChatbotTools::defaultEnabledNames()) and '[]' as
 * a real, deliberate empty selection that must stay exactly that. So every
 * existing '[]' row needs to become NULL, EXCEPT one actually shown by
 * platform_activity_log to have been deliberately touched.
 *
 * In practice that log is staff/admin-panel-only (see PlatformActivity) —
 * no admin page has ever edited a chatbot's enabled_tools, only the
 * customer's own Widget Settings page does, and that page has never logged
 * anywhere. So this check is run in good faith but is not proof of absence:
 * it can show "a human touched this" but an empty result does not prove a
 * merchant never deliberately saved zero tools from their own portal, only
 * that nothing recorded it. Reported honestly either way, not silently.
 *
 * Runs per tenant schema (chatbots lives there, not in the public schema) —
 * see FixTenantsCommand for the established pattern of looping
 * Tenant::all() to reach every schema; this is that same loop as a
 * migration.
 */
return new class extends Migration {
    public function up(): void
    {
        $tenants = DB::table('tenants')->whereNull('deleted_at')->get(['id', 'schema_name', 'email']);
        $keptEmpty = [];
        $convertedCount = 0;

        foreach ($tenants as $tenant) {
            $schema = $tenant->schema_name;

            DB::statement("ALTER TABLE {$schema}.chatbots ALTER COLUMN enabled_tools DROP NOT NULL");
            DB::statement("ALTER TABLE {$schema}.chatbots ALTER COLUMN enabled_tools DROP DEFAULT");

            $chatbots = DB::table("{$schema}.chatbots")->where('enabled_tools', '[]')->get(['id']);

            foreach ($chatbots as $chatbot) {
                $hasLoggedChange = DB::table('platform_activity_log')
                    ->where('subject_id', (string) $chatbot->id)
                    ->where(function ($q) {
                        $q->whereRaw("before::text LIKE ?", ['%enabled_tools%'])
                          ->orWhereRaw("after::text LIKE ?", ['%enabled_tools%']);
                    })
                    ->exists();

                if ($hasLoggedChange) {
                    $keptEmpty[] = "{$chatbot->id} (tenant {$tenant->email})";
                    continue; // stays '[]' — a logged, deliberate choice
                }

                DB::table("{$schema}.chatbots")->where('id', $chatbot->id)->update(['enabled_tools' => null]);
                $convertedCount++;
            }
        }

        $note = 'platform_activity_log only records platform-staff/admin-panel actions; '
              . 'a customer saving their own Widget Settings page has never been logged anywhere, '
              . 'so an empty match here means "nothing recorded it", not "proven unconfigured".';

        if ($keptEmpty) {
            Log::warning("[enabled_tools nullable migration] Left as [] — activity log shows a logged change: "
                . implode(', ', $keptEmpty) . ". {$note}");
        }

        Log::info("[enabled_tools nullable migration] Converted {$convertedCount} chatbot(s) from '[]' to NULL "
            . "(no logged change found for any of them). {$note}");
    }

    public function down(): void
    {
        $tenants = DB::table('tenants')->whereNull('deleted_at')->get(['schema_name']);

        foreach ($tenants as $tenant) {
            $schema = $tenant->schema_name;

            // Exact reversal: nothing else in this codebase ever writes NULL
            // to this column, so every NULL row today came from this
            // migration's own up() — converting back to '[]' is precise.
            DB::table("{$schema}.chatbots")->whereNull('enabled_tools')->update(['enabled_tools' => json_encode([])]);
            DB::statement("ALTER TABLE {$schema}.chatbots ALTER COLUMN enabled_tools SET DEFAULT '[]'");
            DB::statement("ALTER TABLE {$schema}.chatbots ALTER COLUMN enabled_tools SET NOT NULL");
        }
    }
};
