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
use Filament\Forms\Components\{Toggle, TextInput, Section, CheckboxList, Placeholder};
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
 * Content types are a list of the site's own post types (page, post, and
 * any custom type — "portfolio", "service" …), as last reported by the
 * plugin; see SyncSettings::normalize() for the stored shape. Custom fields
 * are chosen on the WordPress side, from a preview of the site's actual
 * meta keys (the portal can't see those); here the owner can only remove
 * one they had approved.
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
    /** @var array<string, string> slug => label, for the post-type checklist */
    public array $postTypeOptions = [];
    /** @var array<string, list<string>> post type => approved meta keys */
    public array $approvedFields = [];

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

        $this->postTypeOptions = $this->postTypeOptionsFrom($settings);
        $this->approvedFields = $settings['custom_fields'];

        $fill = [
            'sync_products'         => $settings['sync_products'],
            'post_types'            => $settings['post_types'],
            'excluded_category_ids' => implode(', ', $settings['excluded_category_ids']),
            'excluded_page_ids'     => implode(', ', $settings['excluded_page_ids']),
        ];
        foreach ($this->approvedFields as $type => $keys) {
            $fill['fields_' . $type] = $keys;
        }
        $this->form->fill($fill);
    }

    public function getTitle(): string {
        return __('chatbot.sync_settings_title', ['name' => $this->chatbotName]);
    }
    public function getSubheading(): ?string { return __('portal.page_sync_settings'); }

    public function form(Form $form): Form {
        return $form->statePath('data')->schema([
            Section::make(__('chatbot.sync_settings_content_types'))
                ->schema([
                    Toggle::make('sync_products')->label(__('chatbot.sync_settings_products')),
                    CheckboxList::make('post_types')
                        ->label(__('portal.sync_post_types_label'))
                        ->helperText(__('portal.sync_post_types_help'))
                        ->options($this->postTypeOptions)
                        ->columns(2),
                ]),
            Section::make(__('portal.sync_custom_fields_title'))
                ->description(__('portal.sync_custom_fields_desc'))
                ->schema($this->customFieldComponents()),
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

    /** page and post are always offered; everything else as the plugin last reported it. */
    private function postTypeOptionsFrom(array $settings): array {
        $options = [
            'page' => __('chatbot.sync_settings_pages'),
            'post' => __('chatbot.sync_settings_posts'),
        ];
        foreach ($settings['available_post_types'] as $slug => $info) {
            if (isset($options[$slug])) continue;
            $options[$slug] = __('portal.sync_post_type_option', ['label' => $info['label'], 'count' => \App\Support\Numbers::format($info['count'])]);
        }
        // A type enabled earlier that the plugin no longer reports still has
        // to be visible here, or it could never be turned off.
        foreach ($settings['post_types'] as $slug) {
            $options[$slug] ??= $slug;
        }
        return $options;
    }

    private function customFieldComponents(): array {
        if (!$this->approvedFields) {
            return [Placeholder::make('no_custom_fields')->hiddenLabel()->content(__('portal.sync_custom_fields_none'))];
        }
        $components = [];
        foreach ($this->approvedFields as $type => $keys) {
            $components[] = CheckboxList::make('fields_' . $type)
                ->label($this->postTypeOptions[$type] ?? $type)
                ->options(array_combine($keys, $keys))
                ->columns(2);
        }
        return $components;
    }

    public function save(): void {
        $data = $this->form->getState();

        $customFields = [];
        foreach (array_keys($this->approvedFields) as $type) {
            // Only removal is possible here; anything not in the approved
            // list for this type is ignored rather than added.
            $customFields[$type] = array_values(array_intersect($this->approvedFields[$type], (array) ($data['fields_' . $type] ?? [])));
        }

        DB::statement("SET search_path TO {$this->schemaName}, public");
        $bot = Chatbot::find($this->chatbotId);
        $archived = 0;
        if ($bot) {
            $result = app(SyncService::class)->applySyncSettings($bot, [
                'sync_products'         => (bool) $data['sync_products'],
                'post_types'            => array_values((array) ($data['post_types'] ?? [])),
                'custom_fields'         => $customFields,
                'excluded_category_ids' => $this->parseIdList($data['excluded_category_ids'] ?? null),
                'excluded_page_ids'     => $this->parseIdList($data['excluded_page_ids'] ?? null),
            ]);
            $archived = $result['archived_documents'];
            $this->approvedFields = $result['settings']['custom_fields'];
        }
        DB::statement('SET search_path TO public');

        $note = Notification::make()->title(__('common.settings_saved'))->success();
        if ($archived > 0) {
            $note->body(__('portal.sync_archived_body', ['count' => \App\Support\Numbers::format($archived)]));
        }
        $note->send();
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
