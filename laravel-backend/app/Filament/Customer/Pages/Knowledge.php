<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotIndexEntry;
use App\Support\{Jalali, KnowledgeSummary, Numbers};
use Filament\Pages\Page;

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
 * The numbers come from App\Support\KnowledgeSummary, which the WordPress
 * plugin's settings page reads too (GET /chatbots/{id}/knowledge), so both
 * places always agree.
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

        $summary = KnowledgeSummary::forChatbot($chatbotId, $tenant);

        $rows = [];
        foreach ($summary['kinds'] as $k) {
            $rows[] = [
                'label'     => $this->kindLabel($k['source_type'], $k['post_type'], $k['post_type_label']),
                'documents' => Numbers::format($k['documents']),
                'chunks'    => Numbers::format($k['chunks']),
                'archived'  => $k['archived'],
                'archived_f'=> Numbers::format($k['archived']),
                'last_sync' => $k['last_sync'] ? Jalali::dateTime($k['last_sync']) : null,
            ];
        }
        $liveTotal = $summary['live_total'];
        $limit = $summary['limit'];
        $overLimit = $summary['over_limit_last_runs'];

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
            'wordpress_page', 'page' => __('chatbot.sync_settings_pages'),
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
