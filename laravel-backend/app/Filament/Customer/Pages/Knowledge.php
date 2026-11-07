<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotIndexEntry;
use App\Models\Plan;
use App\Support\{Jalali, Numbers};
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * What a chatbot actually knows, by kind of content: products, their PDFs,
 * pages, blog posts, each custom post type the site syncs ("portfolio",
 * "service" …) and FAQs — how many documents are live, how many chunks they
 * became, when each kind last synced, and how many sit archived (switched
 * off or deleted at the source).
 *
 * Also the plan's document limit (max_documents, per chatbot, every kind
 * counted together — SyncService::loadDocumentBudget()), and, when the most
 * recent sync runs had to refuse documents because of it, exactly how many,
 * so a limit is never something the merchant discovers by its absence.
 *
 * Reads documents/sync_jobs directly — one grouped query plus one for the
 * latest runs, under a single schema switch.
 */
class Knowledge extends Page {
    protected static string $view = 'filament.customer.pages.knowledge';
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    public ?string $chatbot = null;
    protected $queryString = ['chatbot'];

    public static function getNavigationLabel(): string { return __('portal.knowledge_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('portal.knowledge_nav'); }
    public function getSubheading(): ?string { return __('portal.page_knowledge'); }

    /** @return array<string, string> chatbot_id => name, this tenant's only */
    public function chatbots(): array {
        return ChatbotIndexEntry::where('tenant_id', auth()->user()->tenant_id)
            ->orderBy('name')
            ->pluck('name', 'chatbot_id')
            ->map(fn ($n) => $n ?: __('panel.chatbot_no_name'))
            ->all();
    }

    public function getData(): ?array {
        $chatbots = $this->chatbots();
        if (!$chatbots) return null;

        // Only one of this tenant's own chatbots can be selected — an id from
        // the query string that isn't in the list falls back to the first.
        $chatbotId = array_key_exists((string) $this->chatbot, $chatbots) ? $this->chatbot : array_key_first($chatbots);
        $tenant = auth()->user()->tenant;

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            $groups = DB::select("
                SELECT source_type,
                       metadata->>'post_type' AS post_type,
                       MAX(metadata->>'post_type_label') AS post_type_label,
                       COUNT(*) FILTER (WHERE status <> 'archived') AS documents,
                       COUNT(*) FILTER (WHERE status = 'archived') AS archived,
                       COALESCE(SUM(chunk_count) FILTER (WHERE status <> 'archived'), 0) AS chunks,
                       MAX(last_synced_at) AS last_sync
                FROM documents
                WHERE chatbot_id = ?
                GROUP BY source_type, metadata->>'post_type'
            ", [$chatbotId]);

            // The latest run of each kind — over_limit is per run (see
            // SyncService::upsertDoc()), so only the most recent one says
            // whether the limit is biting right now.
            $latestRuns = DB::select("
                SELECT DISTINCT ON (job_type) job_type, result, created_at
                FROM sync_jobs
                WHERE chatbot_id = ? AND job_type IN ('products', 'pages', 'faqs')
                ORDER BY job_type, created_at DESC
            ", [$chatbotId]);
        } finally {
            DB::statement('SET search_path TO public');
        }

        $rows = [];
        $liveTotal = 0;
        foreach ($groups as $g) {
            $liveTotal += (int) $g->documents;
            $rows[] = [
                'label'     => $this->kindLabel($g->source_type, $g->post_type, $g->post_type_label),
                'documents' => Numbers::format((int) $g->documents),
                'chunks'    => Numbers::format((int) $g->chunks),
                'archived'  => (int) $g->archived,
                'archived_f'=> Numbers::format((int) $g->archived),
                'last_sync' => $g->last_sync ? Jalali::dateTime($g->last_sync) : null,
                'sort'      => (int) $g->documents,
            ];
        }
        usort($rows, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        $overLimit = 0;
        foreach ($latestRuns as $run) {
            $overLimit += (int) (json_decode((string) $run->result, true)['over_limit'] ?? 0);
        }

        $limit = Plan::whereKey($tenant->plan_id)->value('max_documents');

        return [
            'chatbots'   => $chatbots,
            'chatbotId'  => $chatbotId,
            'rows'       => $rows,
            'liveTotal'  => $liveTotal,
            'limit'      => $limit,
            'pct'        => $limit ? min(100, (int) round($liveTotal / max(1, $limit) * 100)) : null,
            'overLimit'  => $overLimit,
        ];
    }

    private function kindLabel(string $sourceType, ?string $postType, ?string $postTypeLabel): string {
        return match ($sourceType) {
            'woocommerce_product' => __('portal.knowledge_kind_products'),
            'product_attachment'  => __('portal.knowledge_kind_pdfs'),
            'wordpress_page'      => __('chatbot.sync_settings_pages'),
            'wordpress_post'      => __('chatbot.sync_settings_posts'),
            'faq'                 => __('portal.knowledge_kind_faqs'),
            'wordpress_cpt'       => $postTypeLabel ?: ($postType ?: __('portal.knowledge_kind_other')),
            default               => $sourceType,
        };
    }

    public function syncSettingsUrl(string $chatbotId): string {
        return SyncSettings::getUrl(['chatbot' => $chatbotId]);
    }
}
