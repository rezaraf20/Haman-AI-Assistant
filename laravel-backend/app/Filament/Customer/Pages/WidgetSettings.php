<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotIndexEntry;
use App\Models\Tenant\Chatbot;
use App\Support\WidgetDefaults;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{TextInput, Textarea, ColorPicker, Select, Toggle, Repeater, CheckboxList, Section};
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
            'enabled_tools'      => ChatbotTools::sanitise($bot?->enabled_tools ?? []),
        ]);
    }

    public function getTitle(): string {
        return __('chatbot.widget_settings_title', ['name' => $this->chatbotName]);
    }

    public function form(Form $form): Form {
        return $form->statePath('data')->schema([
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
                            ->mapWithKeys(fn ($name) => [
                                $name => __('chatbot.tool_' . $name . '_help')
                                    . (ChatbotTools::cost($name) === 'none'
                                        ? ''
                                        : ' - ' . __('chatbot.tool_cost_' . ChatbotTools::cost($name))),
                            ])
                            ->all())
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
                // create_payment_link (doc-04) — NULL (left blank) means
                // the feature stays inert even if the tool is otherwise
                // enabled; see ChatController::createPaymentLink().
                'max_payment_link_amount' => $data['max_payment_link_amount'] !== '' && $data['max_payment_link_amount'] !== null
                    ? (float) $data['max_payment_link_amount'] : null,
                // Sanitised rather than stored as submitted: an unknown name
                // would sit in the column where the panel, which only renders
                // known tools, could never switch it off again.
                'enabled_tools'   => ChatbotTools::sanitise($data['enabled_tools'] ?? []),
                'widget_config'   => $widgetConfig,
            ]);
        }
        DB::statement('SET search_path TO public');

        Notification::make()->title(__('chatbot.widget_settings_saved'))->success()->send();
    }
}
