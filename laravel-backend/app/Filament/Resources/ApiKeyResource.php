<?php
namespace App\Filament\Resources;

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\ChatbotIndexEntry;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, DateTimePicker};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use App\Filament\Resources\ApiKeyResource\Pages;

class ApiKeyResource extends Resource {
    protected static ?string $model = ApiKey::class;
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'API Keys';

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('tenant_id')
                ->label('Tenant')
                ->options(fn () => Tenant::pluck('name', 'id'))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn ($set) => $set('chatbot_id', null)),
            Select::make('chatbot_id')
                ->label('Chatbot / domain')
                ->options(fn ($get) => $get('tenant_id')
                    ? ChatbotIndexEntry::where('tenant_id', $get('tenant_id'))
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->chatbot_id => ($c->name ?? '(unnamed)') . ' — ' . ($c->primary_domain ?? 'no domain')])
                    : [])
                ->helperText('This key will only work for this one chatbot\'s sync/webhook calls.')
                ->required()
                ->disabled(fn ($get) => !$get('tenant_id')),
            TextInput::make('name')->required()->maxLength(255)->placeholder('e.g. "hamantech.ir production key"'),
            DateTimePicker::make('expires_at')->label('Expires')->helperText('Leave blank for a key that never expires.'),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('tenant.name')->label('Tenant')->searchable(),
                TextColumn::make('chatbotIndexEntry.primary_domain')->label('Domain')->default('—'),
                TextColumn::make('key_prefix')->label('Prefix')->fontFamily('mono'),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('expires_at')->dateTime()->placeholder('never')
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null),
                TextColumn::make('last_used_at')->dateTime()->placeholder('never used')->toggleable(),
            ])
            ->actions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ApiKey $record) => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (ApiKey $record) {
                        $record->update(['is_active' => false]);
                        Notification::make()->title('Key revoked')->success()->send();
                    }),
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
