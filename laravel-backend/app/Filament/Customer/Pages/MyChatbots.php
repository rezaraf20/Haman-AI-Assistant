<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotIndexEntry;
use App\Models\Tenant\Chatbot;
use App\Services\WalletService;
use App\Support\WidgetDefaults;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\{ColorPicker, Toggle};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use App\Support\Jalali;
use App\Support\Money;

class MyChatbots extends Page implements HasTable {
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.my-chatbots';
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public static function getNavigationLabel(): string { return __('chatbot.my_chatbots_nav'); }
    public function getTitle(): string { return __('chatbot.my_chatbots_nav'); }

    public function table(Table $table): Table {
        return $table
            ->query(fn () => ChatbotIndexEntry::query()->where('tenant_id', auth()->user()->tenant_id))
            ->columns([
                TextColumn::make('name')->label(__('common.name'))->default(__('panel.chatbot_no_name')),
                TextColumn::make('primary_domain')->label(__('common.domain'))->placeholder('—'),
                IconColumn::make('is_active')->boolean()->label(__('common.active')),
                TextColumn::make('expires_at')->label(__('chatbot.expiry_date'))->formatStateUsing(fn ($state) => Jalali::date($state))
                    ->placeholder(__('common.unlimited'))
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null),
                TextColumn::make('monthly_price_toman')->label(__('chatbot.monthly_renewal_cost'))
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Money::toman($state) : __('chatbot.contact_support')),
            ])
            ->actions([
                Action::make('appearance')
                    ->label(__('chatbot.appearance_action'))
                    ->icon('heroicon-o-swatch')
                    ->color('gray')
                    ->form([
                        ColorPicker::make('primary_color')
                            ->label(__('chatbot.primary_color_label')),
                        Toggle::make('powered_by_enabled')
                            ->label(__('chatbot.powered_by_toggle_label'))
                            ->default(true),
                    ])
                    ->fillForm(fn (ChatbotIndexEntry $record) => $this->loadWidgetConfig($record))
                    ->action(fn (ChatbotIndexEntry $record, array $data) => $this->saveWidgetConfig($record, $data)),
                Action::make('renew')
                    ->label(__('chatbot.renew_action'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (ChatbotIndexEntry $record) => $record->monthly_price_toman > 0)
                    ->requiresConfirmation()
                    ->modalDescription(fn (ChatbotIndexEntry $record) => __('chatbot.renew_confirm_description', ['amount' => Money::toman($record->monthly_price_toman)]))
                    ->action(fn (ChatbotIndexEntry $record) => $this->renew($record)),
            ]);
    }

    // widget_config lives on the tenant-schema Chatbot row, not the
    // public-schema ChatbotIndexEntry this page's table is backed by — same
    // schema-switch pattern BuyChatbot.php already uses for the same reason.
    private function loadWidgetConfig(ChatbotIndexEntry $record): array {
        DB::statement("SET search_path TO {$record->schema_name}, public");
        $chatbot = Chatbot::find($record->chatbot_id);
        $defaults = WidgetDefaults::forLanguage($chatbot?->language);
        $config = array_merge($defaults, $chatbot?->widget_config ?? []);
        DB::statement('SET search_path TO public');

        return [
            'primary_color'      => $config['primary_color'],
            'powered_by_enabled' => $config['powered_by_enabled'],
        ];
    }

    private function saveWidgetConfig(ChatbotIndexEntry $record, array $data): void {
        DB::statement("SET search_path TO {$record->schema_name}, public");
        $chatbot = Chatbot::find($record->chatbot_id);
        if ($chatbot) {
            $chatbot->update([
                'widget_config' => array_merge($chatbot->widget_config ?? [], [
                    'primary_color'      => $data['primary_color'],
                    'powered_by_enabled' => (bool) $data['powered_by_enabled'],
                ]),
            ]);
        }
        DB::statement('SET search_path TO public');

        Notification::make()->title(__('chatbot.appearance_saved'))->success()->send();
    }

    private function renew(ChatbotIndexEntry $record): void {
        $tenant = auth()->user()->tenant;
        $price  = $record->monthly_price_toman;

        if ($tenant->wallet_balance_toman < $price) {
            Notification::make()
                ->title(__('wallet.insufficient_balance'))
                ->body(__('wallet.topup_first'))
                ->danger()
                ->send();
            return;
        }

        app(WalletService::class)->applyCompletedTransaction(
            $tenant,
            'plan_charge',
            -$price,
            ['description' => __('chatbot.renewal_description', ['name' => $record->name])],
        );

        $base = ($record->expires_at && $record->expires_at->isFuture()) ? $record->expires_at : now();
        $record->update([
            'expires_at' => $base->copy()->addMonth(),
            'is_active'  => true,
        ]);

        Notification::make()->title(__('chatbot.renewed_success'))->success()->send();
    }
}
