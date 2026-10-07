<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotIndexEntry;
use App\Models\Tenant\Chatbot;
use App\Support\WidgetDefaults;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{TextInput, Textarea, ColorPicker, Select, Toggle, Repeater, CheckboxList, Section, Grid, TagsInput, TimePicker};
use App\Support\ChatbotTools;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * The single place to edit a chatbot's content/appearance settings — the
 * server (this page), not the WordPress plugin, is now the source of
 * truth (see WidgetDefaults.php / ChatController::mergedWidgetConfig()).
 * Reached from MyChatbots's "تنظیمات ویجت" row action, not the main nav —
 * it's per-chatbot, not a standalone destination.
 *
 * welcome_message and system_instruction write to their own top-level
 * chatbots.welcome_message/system_prompt columns (already the real,
 * RAG-pipeline-facing source of truth for the latter — see ChatService::
 * gatewayPayload()); everything else lives in widget_config. Both paths
 * end up merged into one consistent shape by mergedWidgetConfig() when the
 * widget actually asks for it.
 */
class WidgetSettings extends Page implements HasForms {
    use InteractsWithForms;

    protected static string $view = 'filament.customer.pages.widget-settings';
    protected static bool $shouldRegisterNavigation = false;

    // Without a {chatbot} segment in the route at all, mount(string $chatbot)
    // below can never actually receive one: not from MyChatbots's own row
    // action (WidgetSettings::getUrl(['chatbot' => $record->chatbot_id])
    // silently falls back to appending it as a *query string* instead,
    // since 'chatbot' matches no placeholder in the path), and not from the
    // WordPress plugin's "Edit in the portal" link either. Both produced a
    // real, reachable 500 (BindingResolutionException: "Unable to resolve
    // dependency ... string $chatbot") rather than a page anyone could
    // actually use — found 2026-09-23 from a customer's report of exactly
    // that error on the plugin's link, which turned out to be inherited
    // from this page never having had a working URL at all.
    //
    // Not solved by putting '{chatbot}' directly in $slug: the base
    // HasRoutes trait builds the route's NAME from the same slug string
    // (str($slug)->replace('/', '.')), so the literal braces end up baked
    // into the route name too (filament.customer.pages.widget-settings.
    // {chatbot}) — self-consistent for a raw URL hit, since Laravel's router
    // parses {chatbot} in the PATH independently of what the name looks
    // like, but it broke every route()/getUrl() lookup made without an
    // already-active panel context (this page's own test suite included),
    // because Filament's panel-inference has nothing to go on outside a
    // request and silently guessed the wrong panel. Overriding
    // getRoutePath() alone — instead of overloading $slug for both jobs —
    // keeps the registered NAME plain ("widget-settings") and puts the
    // parameter only where it belongs, in the PATH.
    public static function getRoutePath(): string
    {
        return '/' . static::getSlug() . '/{chatbot}';
    }

    public ?array $data = [];
    public string $chatbotId;
    public string $schemaName;
    public string $chatbotName = '';
    public bool $profileIncomplete = false;

    public function mount(string $chatbot): void {
        $entry = ChatbotIndexEntry::where('chatbot_id', $chatbot)
            ->where('tenant_id', auth()->user()->tenant_id)
            ->firstOrFail();

        $this->chatbotId = $entry->chatbot_id;
        $this->schemaName = $entry->schema_name;
        $this->chatbotName = $entry->name ?? '';

        DB::statement("SET search_path TO {$this->schemaName}, public");
        $bot = Chatbot::find($this->chatbotId);
        $config = $bot ? WidgetDefaults::merge($bot) : WidgetDefaults::forLanguage(null);
        DB::statement('SET search_path TO public');

        $this->form->fill([
            'welcome_message'    => $config['welcome_message'],
            'chat_title'         => $config['chat_title'],
            'ai_name'            => $config['ai_name'],
            'avatar_url'         => $config['avatar_url'],
            'primary_color'      => $config['primary_color'],
            'position'           => $config['position'],
            'powered_by_enabled' => $config['powered_by_enabled'],
            'system_instruction' => $config['system_instruction'],
            'quick_questions'    => $config['quick_questions'],
            'authenticity_unknown_message' => $bot?->authenticity_unknown_message ?? '',
            'max_payment_link_amount' => $bot?->max_payment_link_amount,
            'lead_capture_enabled' => (bool) ($config['lead_capture_enabled'] ?? false),
            'lead_capture_out_of_stock_enabled' => (bool) ($config['lead_capture_out_of_stock_enabled'] ?? false),
            'lead_capture_not_in_catalog_enabled' => (bool) ($config['lead_capture_not_in_catalog_enabled'] ?? false),
            'lead_capture_out_of_stock_prompt' => $config['lead_capture_out_of_stock_prompt'] ?? '',
            'lead_capture_not_in_catalog_prompt' => $config['lead_capture_not_in_catalog_prompt'] ?? '',
            // NULL means never configured — show what's actually active
            // right now (plan defaults) as checked, not an empty list that
            // would wrongly suggest nothing is on. Saving the page from
            // here always writes an explicit array (see save() below), so
            // this is the one moment that reads the live default.
            'enabled_tools'      => $bot && $bot->enabled_tools === null
                ? $bot->effectiveTools(auth()->user()?->tenant)
                : ChatbotTools::sanitise($bot?->enabled_tools ?? []),
            'business_name'      => $bot?->business_name ?? '',
            'business_profile'   => $bot?->business_profile ?? [],
        ]);
        $this->profileIncomplete = (bool) ($bot?->businessProfileMissingCore());
    }

    public function getTitle(): string {
        return __('chatbot.widget_settings_title', ['name' => $this->chatbotName]);
    }

    public function form(Form $form): Form {
        return $form->statePath('data')->schema([
            Section::make(__('chatbot.business_profile_section'))
                ->description(__('chatbot.business_profile_section_help'))
                ->collapsed(fn () => !$this->profileIncomplete)
                ->schema([
                    TextInput::make('business_name')->label(__('chatbot.business_name_label'))->maxLength(255),
                    Grid::make(2)->schema([
                        Textarea::make('business_profile.description.fa')->label(__('chatbot.profile_description_fa'))->rows(2)->maxLength(1000),
                        Textarea::make('business_profile.description.en')->label(__('chatbot.profile_description_en'))->rows(2)->maxLength(1000),
                    ]),
                    Grid::make(2)->schema([
                        Textarea::make('business_profile.address.fa')->label(__('chatbot.profile_address_fa'))->rows(2)->maxLength(500),
                        Textarea::make('business_profile.address.en')->label(__('chatbot.profile_address_en'))->rows(2)->maxLength(500),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('business_profile.city.fa')->label(__('chatbot.profile_city_fa'))->maxLength(100),
                        TextInput::make('business_profile.city.en')->label(__('chatbot.profile_city_en'))->maxLength(100),
                        TextInput::make('business_profile.postal_code')->label(__('chatbot.profile_postal_code'))->maxLength(20),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('business_profile.province.fa')->label(__('chatbot.profile_province_fa'))->maxLength(100),
                        TextInput::make('business_profile.province.en')->label(__('chatbot.profile_province_en'))->maxLength(100),
                    ]),
                    Grid::make(2)->schema([
                        TagsInput::make('business_profile.phones')->label(__('chatbot.profile_phones'))->helperText(__('chatbot.profile_phones_help')),
                        TagsInput::make('business_profile.emails')->label(__('chatbot.profile_emails')),
                    ]),
                    Repeater::make('business_profile.working_hours_schedule')
                        ->label(__('chatbot.profile_hours_label'))
                        ->helperText(__('chatbot.profile_hours_help'))
                        ->schema([
                            CheckboxList::make('days')
                                ->label(__('chatbot.profile_hours_days_label'))
                                ->options(collect(\App\Support\BusinessHours::WEEKDAYS)
                                    ->mapWithKeys(fn ($d) => [$d => __('chatbot.weekday_' . $d)])
                                    ->all())
                                ->columns(4)
                                ->bulkToggleable(),
                            Toggle::make('closed')
                                ->label(__('chatbot.profile_hours_closed_label'))
                                ->live(),
                            Grid::make(2)->schema([
                                TimePicker::make('from')
                                    ->label(__('chatbot.profile_hours_from_label'))
                                    ->seconds(false)
                                    ->visible(fn ($get) => !$get('closed'))
                                    ->required(fn ($get) => !$get('closed')),
                                TimePicker::make('to')
                                    ->label(__('chatbot.profile_hours_to_label'))
                                    ->seconds(false)
                                    ->visible(fn ($get) => !$get('closed'))
                                    ->required(fn ($get) => !$get('closed')),
                            ]),
                        ])
                        ->addActionLabel(__('chatbot.profile_hours_add'))
                        ->maxItems(5)
                        ->live(),
                    Grid::make(2)->schema([
                        Textarea::make('business_profile.working_hours_exceptions.fa')->label(__('chatbot.profile_hours_exceptions_fa'))->helperText(__('chatbot.profile_hours_exceptions_help'))->rows(2)->maxLength(500),
                        Textarea::make('business_profile.working_hours_exceptions.en')->label(__('chatbot.profile_hours_exceptions_en'))->rows(2)->maxLength(500),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('business_profile.social_links.instagram')->label('Instagram')->url()->maxLength(255),
                        TextInput::make('business_profile.social_links.telegram')->label('Telegram')->url()->maxLength(255),
                        TextInput::make('business_profile.social_links.whatsapp')->label('WhatsApp')->maxLength(255),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('business_profile.support_channel.fa')->label(__('chatbot.profile_support_channel_fa'))->maxLength(255),
                        TextInput::make('business_profile.support_channel.en')->label(__('chatbot.profile_support_channel_en'))->maxLength(255),
                    ]),
                    TextInput::make('business_profile.founded_year')->label(__('chatbot.profile_founded_year'))->numeric()->minValue(1300)->maxValue(1500),
                    Grid::make(2)->schema([
                        Textarea::make('business_profile.service_area.fa')->label(__('chatbot.profile_service_area_fa'))->rows(2)->maxLength(500),
                        Textarea::make('business_profile.service_area.en')->label(__('chatbot.profile_service_area_en'))->rows(2)->maxLength(500),
                    ]),
                    Grid::make(2)->schema([
                        Textarea::make('business_profile.payment_methods.fa')->label(__('chatbot.profile_payment_methods_fa'))->rows(2)->maxLength(500),
                        Textarea::make('business_profile.payment_methods.en')->label(__('chatbot.profile_payment_methods_en'))->rows(2)->maxLength(500),
                    ]),
                    Grid::make(2)->schema([
                        Textarea::make('business_profile.return_policy_summary.fa')->label(__('chatbot.profile_return_policy_fa'))->rows(2)->maxLength(1000),
                        Textarea::make('business_profile.return_policy_summary.en')->label(__('chatbot.profile_return_policy_en'))->rows(2)->maxLength(1000),
                    ]),
                    Grid::make(2)->schema([
                        Textarea::make('business_profile.warranty_summary.fa')->label(__('chatbot.profile_warranty_fa'))->rows(2)->maxLength(1000),
                        Textarea::make('business_profile.warranty_summary.en')->label(__('chatbot.profile_warranty_en'))->rows(2)->maxLength(1000),
                    ]),
                ]),

            Textarea::make('welcome_message')
                ->label(__('chatbot.welcome_message_label'))
                ->rows(2)->live(onBlur: true)->maxLength(2000),
            TextInput::make('chat_title')
                ->label(__('chatbot.chat_title_label'))
                ->live(onBlur: true)->maxLength(150),
            TextInput::make('ai_name')
                ->label(__('chatbot.ai_name_label'))
                ->live(onBlur: true)->maxLength(100),
            TextInput::make('avatar_url')
                ->label(__('chatbot.avatar_url_label'))
                ->url()->live(onBlur: true)->maxLength(1000),
            ColorPicker::make('primary_color')
                ->label(__('chatbot.primary_color_label'))
                ->live(),
            Select::make('position')
                ->label(__('chatbot.widget_position_label'))
                ->options([
                    'bottom-right' => __('chatbot.widget_position_bottom_right'),
                    'bottom-left'  => __('chatbot.widget_position_bottom_left'),
                ])
                ->live()->required(),
            Toggle::make('powered_by_enabled')
                ->label(__('chatbot.powered_by_toggle_label')),
            Textarea::make('system_instruction')
                ->label(__('chatbot.system_instruction_label'))
                ->helperText(__('chatbot.system_instruction_help'))
                ->rows(4)->maxLength(10000),
            Textarea::make('authenticity_unknown_message')
                ->label(__('chatbot.authenticity_unknown_message_label'))
                ->helperText(__('chatbot.authenticity_unknown_message_help'))
                ->rows(3)->maxLength(2000),
            TextInput::make('max_payment_link_amount')
                ->label(__('chatbot.max_payment_link_amount_label'))
                ->helperText(__('chatbot.max_payment_link_amount_help'))
                ->numeric()->minValue(0)->maxValue(999999999999)
                ->nullable(),
            // Lead capture. The two product modes are gated behind the
            // master switch in the UI as well as in LeadCaptureService, so
            // a merchant can never leave a mode "on" that silently does
            // nothing because the feature itself is off.
            Toggle::make('lead_capture_enabled')
                ->label(__('chatbot.lead_capture_enabled_label'))
                ->helperText(__('chatbot.lead_capture_enabled_help'))
                ->live(),
            Toggle::make('lead_capture_out_of_stock_enabled')
                ->label(__('chatbot.lead_capture_out_of_stock_label'))
                ->helperText(__('chatbot.lead_capture_out_of_stock_help'))
                ->visible(fn ($get) => $get('lead_capture_enabled'))
                ->live(),
            Textarea::make('lead_capture_out_of_stock_prompt')
                ->label(__('chatbot.lead_capture_out_of_stock_prompt_label'))
                ->helperText(__('chatbot.lead_capture_item_placeholder_help'))
                ->rows(2)->maxLength(1000)
                ->visible(fn ($get) => $get('lead_capture_enabled') && $get('lead_capture_out_of_stock_enabled')),
            Toggle::make('lead_capture_not_in_catalog_enabled')
                ->label(__('chatbot.lead_capture_not_in_catalog_label'))
                ->helperText(__('chatbot.lead_capture_not_in_catalog_help'))
                ->visible(fn ($get) => $get('lead_capture_enabled'))
                ->live(),
            Textarea::make('lead_capture_not_in_catalog_prompt')
                ->label(__('chatbot.lead_capture_not_in_catalog_prompt_label'))
                ->helperText(__('chatbot.lead_capture_item_placeholder_help'))
                ->rows(2)->maxLength(1000)
                ->visible(fn ($get) => $get('lead_capture_enabled') && $get('lead_capture_not_in_catalog_enabled')),
            // The Python side has always gated each tool on this list; until
            // now nothing could write to it, so every chatbot sat at [] and
            // no tool had ever run.
            Section::make(__('chatbot.tools_section'))
                ->description(__('chatbot.tools_section_help'))
                ->collapsed()
                ->schema([
                    CheckboxList::make('enabled_tools')
                        ->label('')
                        ->options(collect(ChatbotTools::names())
                            ->mapWithKeys(fn ($name) => [$name => __('chatbot.tool_' . $name)])
                            ->all())
                        ->descriptions(collect(ChatbotTools::names())
                            ->mapWithKeys(function ($name) {
                                $desc = __('chatbot.tool_' . $name . '_help')
                                    . (ChatbotTools::cost($name) === 'none'
                                        ? ''
                                        : ' - ' . __('chatbot.tool_cost_' . ChatbotTools::cost($name)));
                                // Shown, never hidden, even when the plan
                                // doesn't allow it yet — seeing a locked
                                // capability is what actually drives an
                                // upgrade; a tool that silently isn't there
                                // drives nothing.
                                if (!in_array($name, $this->allowedToolsForTenant(), true)) {
                                    $desc .= ' — ' . __('chatbot.tool_needs_upgrade');
                                }
                                return [$name => $desc];
                            })
                            ->all())
                        // Filament still submits a pre-checked-but-now-
                        // disabled option's value on save (disabling only
                        // blocks toggling it, not the state it already has)
                        // — the real gate is save()'s own intersection with
                        // allowedToolsForTenant(), not this UI-only lock.
                        ->disableOptionWhen(fn (string $value): bool => !in_array($value, $this->allowedToolsForTenant(), true))
                        ->columns(2)
                        ->bulkToggleable(),
                ]),

            Repeater::make('quick_questions')
                ->label(__('chatbot.quick_questions_label'))
                ->schema([
                    TextInput::make('question')->label(__('chatbot.quick_question_label'))->required()->maxLength(300),
                    TextInput::make('answer')->label(__('chatbot.quick_answer_label'))->required()->maxLength(3000),
                ])
                ->live()
                ->columns(2)
                ->addActionLabel(__('chatbot.quick_questions_add'))
                ->maxItems(20),
        ]);
    }

    public function save(): void {
        $data = $this->form->getState();

        DB::statement("SET search_path TO {$this->schemaName}, public");
        $bot = Chatbot::find($this->chatbotId);
        if ($bot) {
            $widgetConfig = array_merge($bot->widget_config ?? [], [
                'chat_title'         => $data['chat_title'],
                'ai_name'            => $data['ai_name'],
                'avatar_url'         => $data['avatar_url'],
                'primary_color'      => $data['primary_color'],
                'position'           => $data['position'],
                'powered_by_enabled' => (bool) $data['powered_by_enabled'],
                'quick_questions'    => $data['quick_questions'] ?? [],
                'lead_capture_enabled' => (bool) $data['lead_capture_enabled'],
                'lead_capture_out_of_stock_enabled'   => (bool) $data['lead_capture_out_of_stock_enabled'],
                'lead_capture_not_in_catalog_enabled' => (bool) $data['lead_capture_not_in_catalog_enabled'],
                'lead_capture_out_of_stock_prompt'    => $data['lead_capture_out_of_stock_prompt'] ?? null,
                'lead_capture_not_in_catalog_prompt'  => $data['lead_capture_not_in_catalog_prompt'] ?? null,
            ]);

            // Left blank means "use the built-in wording". The key has to be
            // REMOVED, not stored as null or "": widget_config is merged over
            // WidgetDefaults, so a present-but-empty key would override the
            // default with nothing and leave the bot silent at exactly the
            // moment it is meant to ask for a number.
            foreach (['lead_capture_out_of_stock_prompt', 'lead_capture_not_in_catalog_prompt'] as $key) {
                if (blank($widgetConfig[$key] ?? null)) unset($widgetConfig[$key]);
            }

            $bot->update([
                'welcome_message' => $data['welcome_message'] ?: null,
                'system_prompt'   => $data['system_instruction'] ?: null,
                'authenticity_unknown_message' => $data['authenticity_unknown_message'] ?: null,
                'business_name'    => $data['business_name'] ?: null,
                // Prune blank-string leaves so an untouched field stays
                // genuinely empty (and so Chatbot::businessProfilePromptBlock()
                // skips it) rather than storing "" that filled()/blank()
                // checks would otherwise have to special-case everywhere.
                'business_profile' => $this->pruneBlank($data['business_profile'] ?? []),
                // create_payment_link (doc-04) — NULL (left blank) means
                // the feature stays inert even if the tool is otherwise
                // enabled; see ChatController::createPaymentLink().
                'max_payment_link_amount' => $data['max_payment_link_amount'] !== '' && $data['max_payment_link_amount'] !== null
                    ? (float) $data['max_payment_link_amount'] : null,
                // Sanitised (an unknown name would sit in the column where
                // the panel, which only renders known tools, could never
                // switch it off again) AND intersected with the tenant's
                // current plan — the UI's own disableOptionWhen() above is
                // cosmetic; this is the real gate a manipulated POST can't
                // get past. See Chatbot::effectiveTools(), which applies
                // this same intersection again on every read — storing only
                // the allowed subset here is belt, that's suspenders.
                'enabled_tools'   => array_values(array_intersect(
                    ChatbotTools::sanitise($data['enabled_tools'] ?? []),
                    $this->allowedToolsForTenant(),
                )),
                'widget_config'   => $widgetConfig,
            ]);
        }
        DB::statement('SET search_path TO public');

        Notification::make()->title(__('chatbot.widget_settings_saved'))->success()->send();
    }

    /** Memoized per request — the form schema's descriptions() closure calls this once per tool option, not once. */
    private ?array $allowedToolsCache = null;
    private function allowedToolsForTenant(): array {
        return $this->allowedToolsCache ??= auth()->user()?->tenant?->plan?->allowed_tools ?? [];
    }

    /** Recursively drops empty-string/null leaves (keeps real 0/false), so an untouched business_profile field is genuinely absent, not stored as "". */
    private function pruneBlank(array $data): array {
        $pruned = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->pruneBlank($value);
                if ($value === []) continue;
                $pruned[$key] = $value;
            } elseif (filled($value)) {
                $pruned[$key] = $value;
            }
        }
        return $pruned;
    }
}
