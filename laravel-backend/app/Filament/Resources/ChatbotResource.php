<?php
namespace App\Filament\Resources;

use App\Models\ChatbotIndexEntry;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, DateTimePicker};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Filters\{TernaryFilter, Filter};
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use App\Filament\Resources\ChatbotResource\Pages;

class ChatbotResource extends Resource {
    protected static ?string $model = ChatbotIndexEntry::class;
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Chatbots';

    public static function form(Form $form): Form {
        return $form->schema([
            TextInput::make('name')->maxLength(255),
            TextInput::make('primary_domain')->maxLength(255)->helperText('Display only — does not change WordPress plugin settings.'),
            DateTimePicker::make('expires_at')->label('Renewal / expiry date')
                ->helperText('If left blank, this chatbot never auto-suspends. The daily chatbots:expire-overdue job suspends it once this date passes.'),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->default('(unnamed)'),
                TextColumn::make('primary_domain')->label('Domain')->searchable(),
                TextColumn::make('tenant.name')->label('Tenant')->searchable(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->sortable()
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null)
                    ->placeholder('never'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                Filter::make('overdue')
                    ->label('Overdue (past expiry, still active)')
                    ->query(fn ($query) => $query->where('is_active', true)->whereNotNull('expires_at')->where('expires_at', '<', now())),
            ])
            ->actions([
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (ChatbotIndexEntry $record) => $record->is_active)
                    ->requiresConfirmation()
                    ->modalDescription('This immediately blocks the public chat widget and the WordPress plugin\'s sync for this chatbot.')
                    ->action(function (ChatbotIndexEntry $record) {
                        $record->update(['is_active' => false]);
                        Notification::make()->title("Suspended {$record->name}")->success()->send();
                    }),
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ChatbotIndexEntry $record) => !$record->is_active)
                    ->action(function (ChatbotIndexEntry $record) {
                        $record->update(['is_active' => true]);
                        Notification::make()->title("Reactivated {$record->name}")->success()->send();
                    }),
                Action::make('edit')->url(fn (ChatbotIndexEntry $record) => static::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array {
        return [
            'index' => Pages\ListChatbots::route('/'),
            'edit'  => Pages\EditChatbot::route('/{record}/edit'),
        ];
    }
}
