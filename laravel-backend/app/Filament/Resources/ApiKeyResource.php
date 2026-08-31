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
    protected static ?string $navigationLabel = 'کلیدهای API';
    protected static ?string $modelLabel = 'کلید API';
    protected static ?string $pluralModelLabel = 'کلیدهای API';

    // See TenantResource — no per-model Policy is registered, and Filament's
    // action visibility otherwise silently hides Create/Edit/Delete without one.
    public static function canCreate(): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('tenant_id')
                ->label('مشتری')
                ->options(fn () => Tenant::pluck('name', 'id'))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn ($set) => $set('chatbot_id', null)),
            Select::make('chatbot_id')
                ->label('چت‌بات / دامنه')
                ->options(fn ($get) => $get('tenant_id')
                    ? ChatbotIndexEntry::where('tenant_id', $get('tenant_id'))
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->chatbot_id => ($c->name ?? '(بدون نام)') . ' — ' . ($c->primary_domain ?? 'بدون دامنه')])
                    : [])
                ->helperText('این کلید فقط برای سینک/وبهوک همین یک چت‌بات کار می‌کنه.')
                ->required()
                ->disabled(fn ($get) => !$get('tenant_id'))
                // Filament excludes disabled() fields from the submitted form
                // data by default ("not dehydrated") — without this, chatbot_id
                // silently saved as null even when a chatbot was visibly
                // selected, and every key ended up unbound to any chatbot.
                ->dehydrated(),
            TextInput::make('name')->label('نام')->required()->maxLength(255)->placeholder('مثلاً «کلید تولید hamantech.ir»'),
            DateTimePicker::make('expires_at')->label('انقضا')->helperText('برای کلید بدون انقضا خالی بگذارید.'),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('name')->label('نام')->searchable(),
                TextColumn::make('tenant.name')->label('مشتری')->searchable(),
                TextColumn::make('chatbotIndexEntry.primary_domain')->label('دامنه')->default('—'),
                // Full key, directly in the table — not just key_prefix. Admin
                // access is unrestricted by design here: getStateUsing (not a
                // real DB column) decrypts key_encrypted per row, same source
                // as the 'showKey' modal action below. Falls back to the
                // masked prefix only for pre-encryption legacy rows that have
                // no reversible copy at all.
                TextColumn::make('full_key')
                    ->label('کلید کامل')
                    ->getStateUsing(fn (ApiKey $record) => $record->revealKey() ?? $record->key_prefix . '… (کلید قدیمی)')
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('کپی شد')
                    ->limit(28),
                IconColumn::make('is_active')->boolean()->label('فعال'),
                TextColumn::make('expires_at')->label('انقضا')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder('نامحدود')
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null),
                TextColumn::make('last_used_at')->label('آخرین استفاده')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder('هیچ‌وقت')->toggleable(),
            ])
            ->actions([
                // Persistent reveal — earlier the full key was only ever shown
                // once (right after creation, via a session-stashed modal on
                // ListApiKeys) and never again. Admin explicitly asked to be
                // able to pull it back up any time, since a customer losing
                // their copy shouldn't mean re-issuing the key.
                Action::make('showKey')
                    ->label('نمایش کلید')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('کلید API کامل')
                    ->modalDescription(fn (ApiKey $record) => $record->revealKey()
                        ? 'این کلید را در جای امنی نگه دارید — هرکس آن را ببیند می‌تواند به‌جای این مشتری درخواست بفرستد.'
                        : 'این کلید قبل از فعال‌شدن قابلیت نمایش مجدد ساخته شده و دیگر در دسترس نیست — برای این چت‌بات یک کلید جدید بسازید.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('بستن')
                    ->form(fn (ApiKey $record) => $record->revealKey() ? [
                        TextInput::make('key')
                            ->label('کلید API')
                            ->default(fn () => $record->revealKey())
                            ->readOnly()
                            ->extraInputAttributes(['class' => 'font-mono text-sm', 'id' => "hamman-key-{$record->id}"])
                            ->suffixActions([
                                FieldAction::make('copy')
                                    ->icon('heroicon-m-clipboard')
                                    ->label('کپی')
                                    ->alpineClickHandler(
                                        "navigator.clipboard.writeText(document.getElementById('hamman-key-{$record->id}').value)"
                                    ),
                            ]),
                    ] : []),
                Action::make('revoke')
                    ->label('لغو')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ApiKey $record) => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (ApiKey $record) {
                        $record->update(['is_active' => false]);
                        Notification::make()->title('کلید لغو شد')->success()->send();
                    }),
                DeleteAction::make()->label('حذف'),
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
