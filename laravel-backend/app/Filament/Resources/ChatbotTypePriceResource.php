<?php
namespace App\Filament\Resources;

use App\Models\ChatbotTypePrice;
use App\Enums\ChatbotType;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, Toggle};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\{EditAction, DeleteAction};
use App\Support\Money;
use App\Filament\Resources\ChatbotTypePriceResource\Pages;

class ChatbotTypePriceResource extends Resource {
    protected static ?string $model = ChatbotTypePrice::class;
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string { return __('plan.chatbot_pricing_nav'); }
    public static function getModelLabel(): string { return __('plan.chatbot_price_singular'); }
    public static function getPluralModelLabel(): string { return __('plan.chatbot_pricing_nav'); }

    public static function canCreate(): bool { return true; }
    public static function canEdit($record): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('type')
                ->label(__('chatbot.type'))
                ->options(array_combine(
                    array_map(fn ($c) => $c->value, ChatbotType::cases()),
                    array_map(fn ($c) => ucfirst($c->value), ChatbotType::cases()),
                ))
                ->required()
                ->unique(ignoreRecord: true),
            TextInput::make('name')->label(__('common.name'))->required()->maxLength(255)->placeholder(__('plan.chatbot_price_name_placeholder')),
            TextInput::make('price_toman')->label(__('plan.price_toman'))->numeric()->required()
                ->helperText(__('plan.chatbot_price_help')),
            Toggle::make('is_active')->label(__('plan.self_purchasable'))->default(true),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('type')->label(__('chatbot.type'))->badge(),
                TextColumn::make('name')->label(__('common.name')),
                TextColumn::make('price_toman')->label(__('plan.price'))
                    ->formatStateUsing(fn (int $state) => Money::toman($state)),
                IconColumn::make('is_active')->label(__('common.active'))->boolean(),
            ])
            ->actions([EditAction::make()->label(__('common.edit')), DeleteAction::make()->label(__('common.delete'))])
            ->defaultSort('type');
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListChatbotTypePrices::route('/'),
            'create' => Pages\CreateChatbotTypePrice::route('/create'),
            'edit'   => Pages\EditChatbotTypePrice::route('/{record}/edit'),
        ];
    }
}
