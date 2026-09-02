<?php
namespace App\Filament\Resources;

use App\Models\ChatbotIndexEntry;
use App\Models\Tenant;
use App\Enums\ChatbotType;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, DateTimePicker, Select};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Filters\{TernaryFilter, Filter};
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use App\Models\ApiKey;
use Illuminate\Support\Facades\DB;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\DomainNormalizer;
use App\Filament\Resources\ChatbotResource\Pages;

class ChatbotResource extends Resource {
    protected static ?string $model = ChatbotIndexEntry::class;
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string { return __('chatbot.nav'); }
    public static function getModelLabel(): string { return __('chatbot.singular'); }
    public static function getPluralModelLabel(): string { return __('chatbot.nav'); }

    // See TenantResource — no per-model Policy is registered, and Filament's
    // action visibility otherwise silently hides Create/Edit/Delete without one.
    public static function canCreate(): bool { return true; }
    public static function canEdit($record): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('tenant_id')
                ->label(__('panel.tenant'))
                ->options(fn () => Tenant::orderBy('name')->pluck('name', 'id'))
                ->searchable()->required()
                ->visibleOn('create'),
            Select::make('type')
                ->label(__('chatbot.type'))
                ->options(array_combine(
                    array_map(fn ($c) => $c->value, ChatbotType::cases()),
                    array_map(fn ($c) => ucfirst($c->value), ChatbotType::cases()),
                ))
                ->required()
                ->visibleOn('create'),
            TextInput::make('name')->label(__('common.name'))->required()->maxLength(255),
            TextInput::make('primary_domain')->label(__('common.domain'))->maxLength(255)
                ->dehydrateStateUsing(fn ($state) => DomainNormalizer::normalize($state))
                ->helperText(__('chatbot.primary_domain_help')),
            DateTimePicker::make('expires_at')->label(__('chatbot.renewal_expiry'))
                ->helperText(__('chatbot.renewal_expiry_help')),
            TextInput::make('monthly_price_toman')
                ->label(__('chatbot.monthly_price'))
                ->numeric()
                ->default(0)
                ->required()
                ->helperText(__('chatbot.monthly_price_help')),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('common.name'))->searchable()->sortable()->default(__('panel.chatbot_no_name')),
                TextColumn::make('primary_domain')->label(__('common.domain'))->searchable(),
                TextColumn::make('tenant.name')->label(__('panel.tenant'))->searchable(),
                IconColumn::make('is_active')->boolean()->label(__('common.active')),
                TextColumn::make('expires_at')
                    ->label(__('chatbot.expires'))
                    ->formatStateUsing(fn ($state) => Jalali::dateTime($state))
                    ->sortable()
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null)
                    ->placeholder(__('common.unlimited')),
                TextColumn::make('monthly_price_toman')->label(__('chatbot.price_per_month'))
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Money::toman($state) : '—'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('common.active')),
                Filter::make('overdue')
                    ->label(__('chatbot.overdue_filter'))
                    ->query(fn ($query) => $query->where('is_active', true)->whereNotNull('expires_at')->where('expires_at', '<', now())),
            ])
            ->actions([
                Action::make('suspend')
                    ->label(__('chatbot.suspend'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (ChatbotIndexEntry $record) => $record->is_active)
                    ->requiresConfirmation()
                    ->modalDescription(__('chatbot.suspend_description'))
                    ->action(function (ChatbotIndexEntry $record) {
                        $record->update(['is_active' => false]);
                        Notification::make()->title(__('chatbot.suspended_notice', ['name' => $record->name]))->success()->send();
                    }),
                Action::make('reactivate')
                    ->label(__('chatbot.reactivate'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ChatbotIndexEntry $record) => !$record->is_active)
                    ->action(function (ChatbotIndexEntry $record) {
                        $record->update(['is_active' => true]);
                        Notification::make()->title(__('chatbot.reactivated_notice', ['name' => $record->name]))->success()->send();
                    }),
                Action::make('edit')->label(__('common.edit'))->url(fn (ChatbotIndexEntry $record) => static::getUrl('edit', ['record' => $record])),
                Action::make('delete')
                    ->label(__('common.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ChatbotIndexEntry $record) => __('chatbot.delete_heading', ['name' => $record->name]))
                    ->modalDescription(__('chatbot.delete_description'))
                    ->action(function (ChatbotIndexEntry $record) {
                        $chatbotId = $record->chatbot_id;
                        $schema    = $record->schema_name;

                        ApiKey::where('chatbot_id', $chatbotId)->delete();

                        DB::statement("SET search_path TO {$schema}, public");
                        DB::table('chatbots')->where('id', $chatbotId)->delete();
                        DB::statement("SET search_path TO public");

                        $record->delete();

                        Notification::make()->title(__('chatbot.deleted_notice'))->success()->send();
                    }),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\DeleteBulkAction::make()
                    ->action(function (\Illuminate\Support\Collection $records) {
                        foreach ($records as $record) {
                            ApiKey::where('chatbot_id', $record->chatbot_id)->delete();
                            DB::statement("SET search_path TO {$record->schema_name}, public");
                            DB::table('chatbots')->where('id', $record->chatbot_id)->delete();
                            DB::statement("SET search_path TO public");
                            $record->delete();
                        }
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListChatbots::route('/'),
            'create' => Pages\CreateChatbot::route('/create'),
            'edit'   => Pages\EditChatbot::route('/{record}/edit'),
        ];
    }
}
