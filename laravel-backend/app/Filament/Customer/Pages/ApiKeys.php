<?php
namespace App\Filament\Customer\Pages;

use App\Models\ApiKey;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Actions\Action as FieldAction;
use App\Support\Jalali;

// Until now the only place a customer ever saw their full API key was a
// one-time reveal modal right after buying a chatbot (BuyChatbot::purchase())
// — lose that copy and the only fix was a support ticket. This page lets them
// pull any of their keys back up on demand, the same way the admin panel now
// can (see ApiKeyResource's 'showKey' action).
class ApiKeys extends Page implements HasTable {
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.api-keys';
    protected static ?string $navigationIcon = 'heroicon-o-key';

    public static function getNavigationLabel(): string { return __('panel.api_keys_nav'); }
    public function getTitle(): string { return __('panel.my_api_keys_title'); }

    public function table(Table $table): Table {
        return $table
            ->query(fn () => ApiKey::query()->where('tenant_id', auth()->user()->tenant_id))
            ->columns([
                TextColumn::make('name')->label(__('common.name')),
                TextColumn::make('chatbotIndexEntry.name')->label(__('chatbot.singular'))->default('—'),
                TextColumn::make('chatbotIndexEntry.primary_domain')->label(__('common.domain'))->default('—'),
                TextColumn::make('key_prefix')->label(__('panel.key_prefix'))->fontFamily('mono'),
                IconColumn::make('is_active')->boolean()->label(__('common.active')),
                TextColumn::make('expires_at')->label(__('panel.expires_at'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder(__('common.unlimited')),
                TextColumn::make('created_at')->label(__('panel.created_date'))->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
            ])
            ->actions([
                Action::make('showKey')
                    ->label(__('panel.show_key'))
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(__('panel.full_api_key_heading'))
                    ->modalDescription(fn (ApiKey $record) => $record->revealKey()
                        ? __('panel.key_reveal_customer_help')
                        : __('panel.key_reveal_customer_unavailable'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('common.close'))
                    ->form(fn (ApiKey $record) => $record->revealKey() ? [
                        TextInput::make('key')
                            ->label(__('panel.api_key'))
                            ->default(fn () => $record->revealKey())
                            ->readOnly()
                            ->extraInputAttributes(['class' => 'font-mono text-sm', 'id' => "hamman-cust-key-{$record->id}"])
                            ->suffixActions([
                                FieldAction::make('copy')
                                    ->icon('heroicon-m-clipboard')
                                    ->label(__('common.copy'))
                                    ->alpineClickHandler(
                                        "navigator.clipboard.writeText(document.getElementById('hamman-cust-key-{$record->id}').value)"
                                    ),
                            ]),
                    ] : []),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
