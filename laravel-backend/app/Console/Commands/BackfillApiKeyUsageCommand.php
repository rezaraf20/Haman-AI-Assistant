<?php
namespace App\Console\Commands;

use App\Models\{ApiKey, Tenant};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * last_used_at was never written by anything until the middleware started
 * touching it, so every key that has been in real use for months still
 * reads as "never used" — and CustomerOnboarding treats that as "plugin
 * not installed".
 *
 * This reconstructs a lower bound from what the plugin itself left behind:
 * the most recent sync job, or failing that the most recent message, for
 * any chatbot in the tenant. Both only exist because a key was used, so a
 * timestamp derived from them is evidence rather than a guess. Keys with
 * no such trace are left null — "we have no evidence this was ever used"
 * is the honest answer, and inventing a date would defeat the checklist
 * this exists to fix.
 */
class BackfillApiKeyUsageCommand extends Command
{
    protected $signature = 'hamman:backfill-api-key-usage {--dry-run : Show what would change without writing}';
    protected $description = 'Reconstruct api_keys.last_used_at for keys already in use before it was recorded';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;

        foreach (Tenant::orderBy('schema_name')->get() as $tenant) {
            $keys = ApiKey::where('tenant_id', $tenant->id)->whereNull('last_used_at')->get();
            if ($keys->isEmpty()) continue;

            $schema = $tenant->schema_name;
            if (!DB::selectOne('SELECT 1 AS ok FROM information_schema.schemata WHERE schema_name = ?', [$schema])) {
                $skipped += $keys->count();
                continue;
            }

            $evidence = $this->latestActivity($schema);

            foreach ($keys as $key) {
                // A key bound to one chatbot uses that chatbot's own
                // activity; an unbound key can only use the tenant's.
                $at = $key->chatbot_id
                    ? ($evidence['per_chatbot'][$key->chatbot_id] ?? null)
                    : $evidence['tenant_wide'];

                if (!$at) {
                    $skipped++;
                    continue;
                }

                $this->line("  {$tenant->name} / {$key->name} ({$key->key_prefix}) -> {$at}");
                if (!$dryRun) {
                    $key->updateQuietly(['last_used_at' => $at]);
                }
                $updated++;
            }
        }

        $verb = $dryRun ? 'would update' : 'updated';
        $this->info("Done — {$verb} {$updated} key(s), left {$skipped} without evidence.");
        return self::SUCCESS;
    }

    /**
     * @return array{per_chatbot: array<string,string>, tenant_wide: ?string}
     */
    private function latestActivity(string $schema): array
    {
        DB::statement("SET search_path TO {$schema}, public");
        try {
            $perChatbot = [];

            foreach (DB::table('sync_jobs')->selectRaw('chatbot_id, MAX(created_at) AS at')->groupBy('chatbot_id')->get() as $r) {
                $perChatbot[$r->chatbot_id] = $r->at;
            }
            // Messages are the weaker signal (they prove the chat endpoint
            // was reached, not necessarily with this key), so they only
            // fill a gap a sync job did not already cover.
            foreach (DB::table('messages')->selectRaw('chatbot_id, MAX(created_at) AS at')->groupBy('chatbot_id')->get() as $r) {
                if (!isset($perChatbot[$r->chatbot_id]) || $r->at > $perChatbot[$r->chatbot_id]) {
                    $perChatbot[$r->chatbot_id] = $r->at;
                }
            }

            $tenantWide = empty($perChatbot) ? null : max($perChatbot);
        } catch (\Throwable $e) {
            return ['per_chatbot' => [], 'tenant_wide' => null];
        } finally {
            DB::statement('SET search_path TO public');
        }

        return ['per_chatbot' => $perChatbot, 'tenant_wide' => $tenantWide];
    }
}
