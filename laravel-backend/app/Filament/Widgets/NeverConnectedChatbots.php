<?php
namespace App\Filament\Widgets;

use App\Support\PlatformAccess;
use App\Support\Settings;
use App\Models\ChatbotIndexEntry;
use App\Support\Jalali;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Cache, Log};

/**
 * "Chatbots that have never connected" — the admin's own outreach/call list
 * and a churn signal at once (2026-10-08 request, triggered by discovering
 * khonehrangi.ir's plugin never once authenticated after signup, with
 * nothing anywhere surfacing that fact to anyone).
 *
 * A chatbot qualifies if, past its own grace period (never_connected_silence
 * _days — a brand-new signup still mid-install should not show up here
 * within minutes of creation, which a literal reading of the three OR'd
 * criteria below would otherwise cause), ANY of:
 *   - it has never once authenticated a plugin API call (api_keys.last_used_at
 *     is null for every key bound to it), or
 *   - it has zero sync_jobs rows ever (content was never pushed, even if a
 *     key did authenticate once), or
 *   - its last real contact (latest of either signal) is older than the
 *     same threshold — a chatbot that connected once and then went silent is
 *     exactly as much a churn risk as one that never connected at all.
 *
 * sync_jobs lives per-tenant-schema like FailedSyncsTable's does, so this is
 * the same one-query-per-tenant shape and the same budget tradeoff — fine at
 * today's tenant count, the first thing to revisit if that changes.
 */
class NeverConnectedChatbots extends Widget {
    public static function canView(): bool { return PlatformAccess::allows('chatbots_read'); }

    protected static string $view = 'filament.widgets.never-connected-chatbots';
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = false;

    public function getRows(): array {
        $days = (int) Settings::get('limits.never_connected_silence_days');

        return Cache::remember("dashboard:admin:never-connected-chatbots:{$days}", 300, function () use ($days) {
            $cutoff = Carbon::now()->subDays($days);
            $rows = [];

            $entries = ChatbotIndexEntry::where('is_active', true)->with('tenant')->get()->groupBy('tenant_id');

            try {
                foreach ($entries as $tenantId => $chatbots) {
                    $tenant = $chatbots->first()->tenant;
                    if (!$tenant) continue;

                    $lastUsedByChatbot = DB::table('api_keys')
                        ->where('tenant_id', $tenantId)
                        ->whereNotNull('chatbot_id')
                        ->selectRaw('chatbot_id, MAX(last_used_at) as last_used_at')
                        ->groupBy('chatbot_id')
                        ->get()
                        ->keyBy('chatbot_id');

                    $syncStatsByChatbot = collect();
                    try {
                        DB::statement("SET search_path TO {$tenant->schema_name}, public");
                        $syncStatsByChatbot = DB::table('sync_jobs')
                            ->selectRaw('chatbot_id, COUNT(*) as job_count, MAX(created_at) as last_sync_at')
                            ->groupBy('chatbot_id')
                            ->get()
                            ->keyBy('chatbot_id');
                    } catch (\Throwable $e) {
                        // Same incomplete-schema protection as FailedSyncsTable
                        // — one bad tenant must never blank the whole dashboard.
                        Log::warning("NeverConnectedChatbots: skipping sync_jobs for tenant {$tenantId} ({$tenant->schema_name}) — {$e->getMessage()}");
                    } finally {
                        DB::statement('SET search_path TO public');
                    }

                    foreach ($chatbots as $chatbot) {
                        $createdAt = Carbon::parse($chatbot->created_at);
                        if ($createdAt->gt($cutoff)) continue; // still within its own grace period

                        $lastUsedAt = $lastUsedByChatbot[$chatbot->chatbot_id]->last_used_at ?? null;
                        $syncRow = $syncStatsByChatbot[$chatbot->chatbot_id] ?? null;
                        $syncCount = (int) ($syncRow->job_count ?? 0);
                        $lastSyncAt = $syncRow->last_sync_at ?? null;

                        $neverConnected = $lastUsedAt === null;
                        $zeroSyncs = $syncCount === 0;

                        $lastContact = collect([$lastUsedAt, $lastSyncAt])
                            ->filter()
                            ->map(fn ($v) => Carbon::parse($v))
                            ->sort()
                            ->last();
                        $goneQuiet = $lastContact ? $lastContact->lt($cutoff) : true;

                        if (!($neverConnected || $zeroSyncs || $goneQuiet)) continue;

                        $sinceTs = $lastContact ?? $createdAt;
                        $rows[] = [
                            'tenant'      => $tenant->name,
                            'domain'      => $chatbot->primary_domain ?: '—',
                            'created_at'  => $chatbot->created_at,
                            'days_silent' => $sinceTs->diffInDays(Carbon::now()),
                        ];
                    }
                }
            } finally {
                DB::statement('SET search_path TO public');
            }

            usort($rows, fn ($a, $b) => $b['days_silent'] <=> $a['days_silent']);
            return $rows;
        });
    }

    public function formatCreatedAt($value): string {
        return Jalali::dateTime($value) ?? '—';
    }
}
