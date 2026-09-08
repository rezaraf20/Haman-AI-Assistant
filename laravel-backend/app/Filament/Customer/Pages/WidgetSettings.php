<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotIndexEntry;
use App\Models\Tenant\Chatbot;
use App\Support\WidgetDefaults;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{TextInput, Textarea, ColorPicker, Select, Toggle, Repeater};
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
            $bot->update([
                'welcome_message' => $data['welcome_message'] ?: null,
                'system_prompt'   => $data['system_instruction'] ?: null,
                'authenticity_unknown_message' => $data['authenticity_unknown_message'] ?: null,
                'widget_config'   => array_merge($bot->widget_config ?? [], [
                    'chat_title'         => $data['chat_title'],
                    'ai_name'            => $data['ai_name'],
                    'avatar_url'         => $data['avatar_url'],
                    'primary_color'      => $data['primary_color'],
                    'position'           => $data['position'],
                    'powered_by_enabled' => (bool) $data['powered_by_enabled'],
                    'quick_questions'    => $data['quick_questions'] ?? [],
                ]),
            ]);
        }
        DB::statement('SET search_path TO public');

        Notification::make()->title(__('chatbot.widget_settings_saved'))->success()->send();
    }
}
