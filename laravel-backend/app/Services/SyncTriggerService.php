<?php
namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\SyncJob;
use Illuminate\Support\Facades\{DB, Http, Log};

/**
 * Asks a customer's site to push a fresh sync.
 *
 * Sync has only ever run in one direction — the plugin decides when to send —
 * so a customer whose catalogue had gone stale could not be helped by anyone,
 * even though refreshing it is one of the support role's declared duties and
 * `sync_triggered` was already a registered activity-log action with no
 * button behind it.
 *
 * The counts reported back are NOT the plugin's. It only says how many items
 * it pushed; new/updated/skipped/deleted are decided here, when the push is
 * processed, and land on sync_jobs. So this triggers the sync and then reads
 * the jobs that the push created.
 */
class SyncTriggerService
{
    /** A full sync re-embeds the catalogue, so give it room. */
    private const TIMEOUT_SECONDS = 120;

    public function __construct(private LiveQueryClient $client) {}

    /**
     * @return array{ok:bool, reason?:string, message_key:string, counts?:array,
     *               plugin_version?:string, duration?:int, params?:array}
     */
    public function trigger(Tenant $tenant, string $chatbotId, ?string $domain = null): array
    {
        $domain ??= DB::table('chatbot_index')->where('chatbot_id', $chatbotId)->value('primary_domain');
        $secret = $tenant->getWebhookSecret();

        if (!$domain) {
            return ['ok' => false, 'reason' => 'no_domain', 'message_key' => 'sync_trigger.no_domain'];
        }
        if (!$secret) {
            return ['ok' => false, 'reason' => 'no_secret', 'message_key' => 'sync_trigger.no_secret'];
        }

        $before = $this->latestJobId($tenant->schema_name, $chatbotId);
        $startedAt = now();

        $response = $this->post($domain, $secret, '/trigger-sync');

        if ($response === null) {
            return ['ok' => false, 'reason' => 'unreachable', 'message_key' => 'sync_trigger.unreachable',
                    'params' => ['domain' => $domain]];
        }

        // A 404 means this route does not exist on that site. That is either
        // "no plugin at all" or "a plugin too old to have it" — two different
        // conversations to have with the customer, so they are told apart by
        // probing the route that has been there since 1.4.
        if ($response->status() === 404) {
            $installed = $this->pluginResponds($domain, $secret);

            return $installed
                ? ['ok' => false, 'reason' => 'plugin_outdated', 'message_key' => 'sync_trigger.plugin_outdated',
                   'params' => ['required' => '1.9.0']]
                : ['ok' => false, 'reason' => 'plugin_missing', 'message_key' => 'sync_trigger.plugin_missing',
                   'params' => ['domain' => $domain]];
        }

        if ($response->status() === 403) {
            return ['ok' => false, 'reason' => 'bad_secret', 'message_key' => 'sync_trigger.bad_secret'];
        }
        if ($response->status() === 429) {
            return ['ok' => false, 'reason' => 'rate_limited', 'message_key' => 'sync_trigger.rate_limited',
                    'params' => ['hours' => (int) ($response->json('retry_after_hours') ?? 1)]];
        }
        if ($response->status() === 409) {
            return ['ok' => false, 'reason' => 'not_configured', 'message_key' => 'sync_trigger.not_configured'];
        }
        if ($response->status() === 503) {
            return ['ok' => false, 'reason' => 'sync_unavailable', 'message_key' => 'sync_trigger.sync_unavailable'];
        }
        if (!$response->successful()) {
            Log::warning("Manual sync for {$domain} returned HTTP " . $response->status());
            return ['ok' => false, 'reason' => 'failed', 'message_key' => 'sync_trigger.failed',
                    'params' => ['status' => $response->status()]];
        }

        return [
            'ok'             => true,
            'message_key'    => 'sync_trigger.done',
            'plugin_version' => (string) ($response->json('plugin_version') ?? ''),
            'duration'       => (int) ($response->json('duration_seconds') ?? 0),
            'counts'         => $this->countsSince($tenant->schema_name, $chatbotId, $before, $startedAt),
        ];
    }

    /**
     * The jobs the push just created. Bounded by both the last job id seen
     * before the trigger and the start time, so a sync the plugin happened
     * to run on its own schedule a moment earlier is not counted as ours.
     */
    private function countsSince(string $schema, string $chatbotId, ?string $beforeId, $startedAt): array
    {
        $totals = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0, 'failed' => 0, 'jobs' => 0];

        if (!$this->hasSyncJobs($schema)) return $totals;

        DB::statement("SET search_path TO {$schema}, public");
        try {
            $jobs = SyncJob::where('chatbot_id', $chatbotId)
                ->where('created_at', '>=', $startedAt)
                ->when($beforeId, fn ($q) => $q->where('id', '!=', $beforeId))
                ->get();

            foreach ($jobs as $job) {
                $totals['jobs']++;
                foreach (['new', 'updated', 'skipped', 'deleted', 'failed'] as $key) {
                    $totals[$key] += (int) ($job->result[$key] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not read sync job counts: ' . $e->getMessage());
        } finally {
            $this->resetSearchPath();
        }

        return $totals;
    }

    private function latestJobId(string $schema, string $chatbotId): ?string
    {
        if (!$this->hasSyncJobs($schema)) return null;

        DB::statement("SET search_path TO {$schema}, public");
        try {
            return SyncJob::where('chatbot_id', $chatbotId)->orderByDesc('created_at')->value('id');
        } catch (\Throwable) {
            return null;
        } finally {
            $this->resetSearchPath();
        }
    }

    /**
     * Asked before querying rather than discovered by catching an exception.
     *
     * In Postgres a failed statement aborts the whole transaction, so every
     * later statement — including the search_path reset in a finally block —
     * fails too and escapes as a bare QueryException. Checking first keeps a
     * tenant whose schema is incomplete from turning a reportable sync
     * result into a transaction error.
     */
    private function hasSyncJobs(string $schema): bool
    {
        try {
            return (bool) DB::selectOne(
                "SELECT 1 AS present FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = 'sync_jobs'",
                [$schema],
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /** Never throws: it runs in a finally, where an exception would mask the real one. */
    private function resetSearchPath(): void
    {
        try {
            DB::statement('SET search_path TO public');
        } catch (\Throwable) {
        }
    }

    /**
     * Does the plugin answer at all? live-query has existed since 1.4, so a
     * 403 (signature rejected) proves it is installed, while a 404 proves it
     * is not. Sent deliberately unsigned — we only care which error comes
     * back, not the contents.
     */
    private function pluginResponds(string $domain, string $secret): bool
    {
        $response = $this->post($domain, 'not-the-real-secret', '/live-query', ['action' => 'ping']);

        return $response !== null && $response->status() !== 404;
    }

    private function post(string $domain, string $secret, string $path, array $payload = [])
    {
        $body = json_encode($payload ?: new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $scheme = LiveQueryClient::scheme($domain);

        try {
            return Http::withBody($body, 'application/json')
                ->withHeaders(['X-Hamman-Signature' => 'sha256=' . hash_hmac('sha256', $body, $secret)])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post("{$scheme}://{$domain}/wp-json/hamman/v1" . $path);
        } catch (\Throwable $e) {
            Log::warning("Manual sync call to {$domain}{$path} failed: " . $e->getMessage());
            return null;
        }
    }
}
