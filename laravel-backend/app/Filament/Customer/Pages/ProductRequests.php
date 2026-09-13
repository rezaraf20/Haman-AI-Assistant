<?php
namespace App\Filament\Customer\Pages;

use App\Support\TextSimilarity;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What customers asked for and could not buy — the shop's own demand
 * signal, in the two shapes it comes in:
 *
 *   Waiting for restock  — products the catalog HAS, currently out of
 *                          stock, with the people waiting on each.
 *   Not in the catalog   — products or brands the shop does not carry at
 *                          all, ordered by how many people asked.
 *
 * Built from the leads those moments already produce (LeadCaptureService's
 * out_of_stock / not_in_catalog modes), so there is one source of truth
 * rather than a parallel table that could disagree with the lead list.
 *
 * Rows are grouped requests, not individual leads: "4 people want this" is
 * the unit a merchant acts on. Restock rows group on the real WooCommerce
 * product id when one is known, so two spellings of the same product name
 * never split into two rows; catalog rows have no id to group on and fall
 * back to normalised-text similarity.
 */
class ProductRequests extends Page
{
    protected static string $view = 'filament.customer.pages.product-requests';
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    /** open | fulfilled | rejected | all */
    public string $statusFilter = 'open';

    public static function getNavigationLabel(): string { return __('requests.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('requests.nav'); }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = in_array($status, ['open', 'fulfilled', 'rejected', 'all'], true) ? $status : 'open';
    }

    public function statusOptions(): array
    {
        return [
            'open'      => __('requests.status_open'),
            'fulfilled' => __('requests.status_fulfilled'),
            'rejected'  => __('requests.status_rejected'),
            'all'       => __('requests.status_all'),
        ];
    }

    /** @return array{waiting: array, missing: array} */
    public function getGroups(): array
    {
        $rows = $this->fetchRows();

        return [
            'waiting' => $this->group($rows->where('type', 'out_of_stock')->all()),
            'missing' => $this->group($rows->where('type', 'not_in_catalog')->all()),
        ];
    }

    private function fetchRows()
    {
        $tenant = auth()->user()->tenant;
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            $q = DB::table('leads')
                ->whereIn('type', ['out_of_stock', 'not_in_catalog'])
                ->orderBy('created_at');
            if ($this->statusFilter !== 'all') {
                $q->where('request_status', $this->statusFilter);
            }
            return $q->limit(2000)->get();
        } catch (\Throwable $e) {
            return collect();
        } finally {
            DB::statement('SET search_path TO public');
        }
    }

    /**
     * One row per thing asked for. Keyed by product id where we have one —
     * that is exact — and by token similarity otherwise, so "مدیکوب" and
     * "برند مدیکوب" do not become two separate shopping-list entries.
     */
    private function group(array $leads): array
    {
        $groups = [];

        foreach ($leads as $lead) {
            $item = trim((string) ($lead->requested_item ?? ''));
            if ($item === '') continue;

            $key = null;
            if (!empty($lead->requested_product_id)) {
                $key = 'pid:' . $lead->requested_product_id;
            } else {
                $tokens = TextSimilarity::tokens($item);
                foreach ($groups as $existingKey => $g) {
                    if (str_starts_with($existingKey, 'pid:')) continue;
                    if (TextSimilarity::jaccard($tokens, $g['tokens']) >= 0.5) {
                        $key = $existingKey;
                        break;
                    }
                }
                $key ??= 'txt:' . substr(sha1(TextSimilarity::normalize($item)), 0, 16);
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'        => $key,
                    'item'       => $item,
                    'product_id' => $lead->requested_product_id ?? null,
                    'tokens'     => TextSimilarity::tokens($item),
                    'contacts'   => [],
                    'first'      => $lead->created_at,
                    'last'       => $lead->created_at,
                    'statuses'   => [],
                ];
            }

            $groups[$key]['contacts'][] = [
                'contact'         => $lead->contact,
                'contact_type'    => $lead->contact_type,
                'conversation_id' => $lead->conversation_id,
                'created_at'      => $lead->created_at,
            ];
            $groups[$key]['statuses'][] = $lead->request_status ?? 'open';
            if ($lead->created_at < $groups[$key]['first']) $groups[$key]['first'] = $lead->created_at;
            if ($lead->created_at > $groups[$key]['last'])  $groups[$key]['last']  = $lead->created_at;
        }

        $out = array_map(function ($g) {
            $statuses = array_unique($g['statuses']);
            return [
                'key'        => $g['key'],
                'item'       => $g['item'],
                'product_id' => $g['product_id'],
                'people'     => count($g['contacts']),
                'contacts'   => $g['contacts'],
                'first'      => $g['first'],
                'last'       => $g['last'],
                // A group is only "settled" when every request in it is.
                'status'     => count($statuses) === 1 ? reset($statuses) : 'open',
            ];
        }, array_values($groups));

        usort($out, fn ($a, $b) => $b['people'] <=> $a['people']);
        return $out;
    }

    /** Marks every request in one group. The group is the unit the
     *  merchant sees, so it has to be the unit they can act on. */
    public function markGroup(string $key, string $status): void
    {
        if (!in_array($status, ['open', 'fulfilled', 'rejected'], true)) return;

        $group = collect(array_merge($this->getGroups()['waiting'], $this->getGroups()['missing']))
            ->firstWhere('key', $key);
        if (!$group) return;

        $contacts = array_column($group['contacts'], 'contact');
        $tenant = auth()->user()->tenant;

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            DB::table('leads')
                ->whereIn('type', ['out_of_stock', 'not_in_catalog'])
                ->whereIn('contact', $contacts)
                ->when($group['product_id'], fn ($q, $pid) => $q->where('requested_product_id', $pid))
                ->when(!$group['product_id'], fn ($q) => $q->where('requested_item', $group['item']))
                ->update(['request_status' => $status]);
        } finally {
            DB::statement('SET search_path TO public');
        }

        Notification::make()->title(__('requests.marked', ['status' => $this->statusOptions()[$status] ?? $status]))->success()->send();
    }

    public function conversationUrl(string $conversationId): string
    {
        return Conversations::getUrl() . '?id=' . urlencode($conversationId);
    }

    /** Contacts as one newline-joined block, so a merchant can select and
     *  copy the whole waiting list in one go. */
    public function contactBlock(array $group): string
    {
        return implode("\n", array_column($group['contacts'], 'contact'));
    }

    public function exportCsv(): StreamedResponse
    {
        $groups = $this->getGroups();

        return response()->streamDownload(function () use ($groups) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                __('requests.col_section'), __('requests.col_item'), __('requests.col_people'),
                __('requests.col_first'), __('requests.col_last'), __('common.status'),
                __('requests.col_contact'), __('requests.col_contact_date'),
            ]);

            foreach (['waiting' => __('requests.section_waiting'), 'missing' => __('requests.section_missing')] as $bucket => $label) {
                foreach ($groups[$bucket] as $g) {
                    // One line per waiting person: this file is meant to be
                    // handed to whoever makes the calls.
                    foreach ($g['contacts'] as $c) {
                        fputcsv($out, [
                            $label, $g['item'], $g['people'],
                            (string) $g['first'], (string) $g['last'],
                            $g['status'], $c['contact'], (string) $c['created_at'],
                        ]);
                    }
                }
            }
            fclose($out);
        }, 'product-requests-' . now()->toDateString() . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
