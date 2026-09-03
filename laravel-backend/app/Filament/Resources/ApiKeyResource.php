<?php
namespace App\Filament\Resources;

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\ChatbotIndexEntry;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, DateTimePicker};
use Filament\Forms\Components\Actions\Action as FieldAction;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\{Action, DeleteAction};
use Filament\Notifications\Notification;
use App\Support\Jalali;
use App\Filament\Resources\ApiKeyResource\Pages;

class ApiKeyResource extends Resource {
    protected static ?string $model = ApiKey::class;
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string { return __('panel.api_keys_nav'); }
    public static function getModelLabel(): string { return __('panel.api_key_singular'); }
    public static function getPluralModelLabel(): string { return __('panel.api_keys_nav'); }

    // See TenantResource — no per-model Policy is registered, and Filament's
    // action visibility otherwise silently hides Create/Edit/Delete without one.
    public static function canCreate(): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('tenant_id')
                ->label(__('panel.tenant'))
                ->options(fn () => Tenant::pluck('name', 'id'))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn ($set) => $set('chatbot_id', null)),
            Select::make('chatbot_id')
                ->label(__('panel.chatbot_domain_select'))
                ->options(fn ($get) => $get('tenant_id')
                    ? ChatbotIndexEntry::where('tenant_id', $get('tenant_id'))
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->chatbot_id => ($c->name ?? __('panel.chatbot_no_name')) . ' — ' . ($c->primary_domain ?? __('panel.no_domain'))])
                    : [])
                ->helperText(__('panel.api_key_chatbot_help'))
                ->required()
                ->disabled(fn ($get) => !$get('tenant_id'))
                // Filament excludes disabled() fields from the submitted form
                // data by default ("not dehydrated") — without this, chatbot_id
                // silently saved as null even when a chatbot was visibly
                // selected, and every key ended up unbound to any chatbot.
                ->dehydrated(),
            TextInput::make('name')->label(__('common.name'))->required()->maxLength(255)->placeholder(__('panel.api_key_name_placeholder')),
            DateTimePicker::make('expires_at')->label(__('panel.expires_at'))->helperText(__('panel.expires_at_help')),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('common.name'))->searchable(),
                TextColumn::make('tenant.name')->label(__('panel.tenant'))->searchable(),
                TextColumn::make('chatbotIndexEntry.primary_domain')->label(__('common.domain'))->default('—'),
                // Full key, directly in the table — not just key_prefix. Admin
                // access is unrestricted by design here: getStateUsing (not a
                // real DB column) decrypts key_encrypted per row, same source
                // as the 'showKey' modal action below. Falls back to the
                // masked prefix only for pre-encryption legacy rows that have
                // no reversible copy at all.
                TextColumn::make('full_key')
                    ->label(__('panel.full_key'))
                    ->getStateUsing(fn (ApiKey $record) => $record->revealKey() ?? $record->key_prefix . __('panel.legacy_key_suffix'))
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage(__('common.copied'))
                    ->limit(28),
                IconColumn::make('is_active')->boolean()->label(__('common.active')),
                TextColumn::make('expires_at')->label(__('panel.expires_at'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder(__('common.unlimited'))
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null),
                TextColumn::make('last_used_at')->label(__('panel.last_used_at'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder(__('common.never'))->toggleable(),
            ])
            ->actions([
                // Persistent reveal — earlier the full key was only ever shown
                // once (right after creation, via a session-stashed modal on
                // ListApiKeys) and never again. Admin explicitly asked to be
                // able to pull it back up any time, since a customer losing
                // their copy shouldn't mean re-issuing the key.
                Action::make('showKey')
                    ->label(__('panel.show_key'))
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(__('panel.full_api_key_heading'))
                    ->modalDescription(fn (ApiKey $record) => $record->revealKey()
                        ? __('panel.key_reveal_warning')
                        : __('panel.key_reveal_unavailable'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('common.close'))
                    ->form(fn (ApiKey $record) => $record->revealKey() ? [
                        TextInput::make('key')
                            ->label(__('panel.api_key'))
                            ->default(fn () => $record->revealKey())
                            ->readOnly()
                            ->extraInputAttributes(['class' => 'font-mono text-sm', 'id' => "hamman-key-{$record->id}"])
                            ->suffixActions([
                                FieldAction::make('copy')
                                    ->icon('heroicon-m-clipboard')
                                    ->label(__('common.copy'))
                                    ->alpineClickHandler(
                                        "navigator.clipboard.writeText(document.getElementById('hamman-key-{$record->id}').value)"
                                    ),
                            ]),
                    ] : []),
                Action::make('revoke')
                    ->label(__('panel.revoke'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ApiKey $record) => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (ApiKey $record) {
                        $record->update(['is_active' => false]);
                        Notification::make()->title(__('panel.key_revoked'))->success()->send();
                    }),
                DeleteAction::make()->label(__('common.delete')),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListApiKeys::route('/'),
            'create' => Pages\CreateApiKey::route('/create'),
        ];
    }
}
