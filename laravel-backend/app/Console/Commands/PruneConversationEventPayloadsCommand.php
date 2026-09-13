<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};
use App\Support\Settings;

// conversation_events rows themselves are kept forever (AggregateAnalyticsJob
// and the eval-set builder both need the event_type/timestamps/latency_ms
// history indefinitely) — only the payload (raw query text, chunk ids,
// citations, etc.) ages out after the retention window, since that's the
// part carrying anything resembling real user content.
class PruneConversationEventPayloadsCommand extends Command {
    protected $signature   = 'hamman:prune-event-payloads {--days= : Payloads older than this many days get nulled out (default: the settings page value)}';
    protected $description = 'Null out conversation_events.payload past the retention window, keeping the event row itself';

    public function handle(): void {
        // Configurable from the settings page; the flag still wins for a
        // one-off run.
        $days = (int) ($this->option('days') ?: Settings::get('system.retention_event_payload_days'));
        $cutoff = now()->subDays($days);
        $totalPruned = 0;
        $tenantsChecked = 0;

        // Every tenant with a schema, not just active()/trial() ones — a
        // cancelled or suspended tenant's old data still needs pruning on
        // the same schedule; retention isn't conditional on billing status.
        Tenant::whereNotNull('schema_name')->chunk(20, function ($tenants) use ($cutoff, &$totalPruned, &$tenantsChecked) {
            foreach ($tenants as $tenant) {
                $tenantsChecked++;
                try {
                    DB::statement("SET search_path TO {$tenant->schema_name}, public");
                    $pruned = DB::table('conversation_events')
                        ->where('created_at', '<', $cutoff)
                        ->whereNotNull('payload')
                        ->update(['payload' => null]);
                    $totalPruned += $pruned;
                } catch (\Throwable $e) {
                    // A tenant with an incomplete/broken schema (see
                    // FailedSyncsTable's identical fix — a real, seen
                    // production case) must not stop this from running for
                    // every other tenant.
                    Log::warning("hamman:prune-event-payloads: skipping tenant {$tenant->id} ({$tenant->schema_name}) — {$e->getMessage()}");
                } finally {
                    DB::statement('SET search_path TO public');
                }
            }
        });

        $this->info("Checked {$tenantsChecked} tenant(s); pruned payloads on {$totalPruned} conversation_events row(s) older than {$days} days.");
    }
}
