<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotIndexEntry;
use App\Models\Tenant\Chatbot;
use App\Services\SyncService;
use App\Support\SyncSettings as SyncSettingsSupport;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{Toggle, TextInput, Section};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Which WordPress content types a chatbot's index is allowed to contain —
 * the portal-side half of App\Support\SyncSettings (see that class's own
 * docblock for why the portal is the authoritative side and how the
 * WordPress plugin stays in step with it). Reached from MyChatbots's row
 * action, same pattern as WidgetSettings.php (including its {chatbot} route
 * fix — see that page's docblock for the bug this mirrors and avoids).
 *
 * Built after a real incident: an unfiltered sync had indexed a hamantech.ir
 * blog post, and the bot surfaced its pricing table mid-conversation to a
 * customer who never asked about it. "Clear and reindex" exists because
 * turning a content type off here only stops FUTURE syncs from adding more
 * of it — whatever was already indexed under the old, wider scope stays
 * until something actually removes it.
 */
class SyncSettings extends Page implements HasForms {
    use InteractsWithForms;

    protected static string $view = 'filament.customer.pages.sync-settings';
    protected static bool $shouldRegisterNavigation = false;

    public static function getRoutePath(): string
    {
        return '/' . static::getSlug() . '/{chatbot}';
    }

    public ?array $data = [];
    public string $chatbotId;
    public string $schemaName;
    public string $chatbotName = '';

    public function mount(string $chatbot): void {
        $entry = ChatbotIndexEntry::where('chatbot_id', $chatbot)
            ->where('tenant_id', auth()->user()->tenant_id)
            ->firstOrFail();

        $this->chatbotId = $entry->chatbot_id;
        $this->schemaName = $entry->schema_name;
        $this->chatbotName = $entry->name ?? '';

        DB::statement("SET search_path TO {$this->schemaName}, public");
        $bot = Chatbot::find($this->chatbotId);
        $settings = $bot ? SyncSettingsSupport::merge($bot) : SyncSettingsSupport::DEFAULTS;
        DB::statement('SET search_path TO public');

        $this->form->fill([
            'sync_products'         => $settings['sync_products'],
            'sync_pages'            => $settings['sync_pages'],
            'sync_posts'            => $settings['sync_posts'],
            'excluded_category_ids' => implode(', ', $settings['excluded_category_ids']),
            'excluded_page_ids'     => implode(', ', $settings['excluded_page_ids']),
        ]);
    }

    public function getTitle(): string {
        return __('chatbot.sync_settings_title', ['name' => $this->chatbotName]);
    }

    public function form(Form $form): Form {
        return $form->statePath('data')->schema([
            Section::make(__('chatbot.sync_settings_content_types'))
                ->schema([
                    Toggle::make('sync_products')->label(__('chatbot.sync_settings_products')),
                    Toggle::make('sync_pages')->label(__('chatbot.sync_settings_pages')),
                    Toggle::make('sync_posts')->label(__('chatbot.sync_settings_posts'))
                        ->helperText(__('chatbot.sync_settings_posts_help')),
                ]),
            Section::make(__('chatbot.sync_settings_exclusions'))
                ->schema([
                    TextInput::make('excluded_category_ids')
                        ->label(__('chatbot.sync_settings_excluded_categories'))
                        ->helperText(__('chatbot.sync_settings_excluded_categories_help')),
                    TextInput::make('excluded_page_ids')
                        ->label(__('chatbot.sync_settings_excluded_pages'))
                        ->helperText(__('chatbot.sync_settings_excluded_pages_help')),
                ]),
        ]);
    }

    private function parseIdList(?string $raw): array {
        if (!$raw) return [];
        return collect(explode(',', $raw))
            ->map(fn ($v) => (int) trim($v))
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function save(): void {
        $data = $this->form->getState();

        DB::statement("SET search_path TO {$this->schemaName}, public");
        $bot = Chatbot::find($this->chatbotId);
        if ($bot) {
            $bot->update(['sync_settings' => [
                'sync_products'         => (bool) $data['sync_products'],
                'sync_pages'            => (bool) $data['sync_pages'],
                'sync_posts'            => (bool) $data['sync_posts'],
                'excluded_category_ids' => $this->parseIdList($data['excluded_category_ids'] ?? null),
                'excluded_page_ids'     => $this->parseIdList($data['excluded_page_ids'] ?? null),
            ]]);
        }
        DB::statement('SET search_path TO public');

        Notification::make()->title(__('common.settings_saved'))->success()->send();
    }

    /**
     * Wipes this chatbot's synced documents (never manually-entered FAQs —
     * see SyncService::clearSyncedIndex()) and immediately triggers a real
     * full sync through the same path MyChatbots's own manual-sync action
     * uses, so the index comes back populated with only what the settings
     * above now allow, rather than sitting empty until the plugin's next
     * scheduled run.
     */
    public function clearAndReindex(): void {
        DB::statement("SET search_path TO {$this->schemaName}, public");
        $result = app(SyncService::class)->clearSyncedIndex($this->chatbotId);
        DB::statement('SET search_path TO public');

        $tenant = auth()->user()->tenant;
        $synced = \App\Filament\Resources\TenantResource::runManualSync($tenant, $this->chatbotId);

        Notification::make()
            ->title(__('chatbot.sync_settings_cleared_title'))
            ->body(__('chatbot.sync_settings_cleared_body', [
                'documents' => $result['documents_deleted'],
                'chunks'    => $result['chunks_deleted'],
            ]))
            ->success()
            ->send();

        if (!$synced) {
            Notification::make()
                ->title(__('sync_trigger.failed_title'))
                ->warning()
                ->send();
        }
    }
}
