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
use App\Filament\Resources\ChatbotTypePriceResource\Pages;

class ChatbotTypePriceResource extends Resource {
    protected static ?string $model = ChatbotTypePrice::class;
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?int $navigationSort = 6;
    protected static ?string $navigationLabel = 'قیمت‌گذاری چت‌بات';
    protected static ?string $modelLabel = 'قیمت چت‌بات';
    protected static ?string $pluralModelLabel = 'قیمت‌گذاری چت‌بات‌ها';

    public static function canCreate(): bool { return true; }
    public static function canEdit($record): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('type')
                ->label('نوع')
                ->options(array_combine(
                    array_map(fn ($c) => $c->value, ChatbotType::cases()),
                    array_map(fn ($c) => ucfirst($c->value), ChatbotType::cases()),
                ))
                ->required()
                ->unique(ignoreRecord: true),
            TextInput::make('name')->label('نام')->required()->maxLength(255)->placeholder('مثلاً «چت‌بات پشتیبانی»'),
            TextInput::make('price_toman')->label('قیمت (تومان)')->numeric()->required()
                ->helperText('هنگامی که مشتری خودش یک چت‌بات جدید از این نوع می‌خره از کیف پولش کسر می‌شه و به‌عنوان قیمت پیش‌فرض تمدید ماهانه هم استفاده می‌شه.'),
            Toggle::make('is_active')->label('قابل خرید خودکار توسط مشتری')->default(true),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('type')->label('نوع')->badge(),
                TextColumn::make('name')->label('نام'),
                TextColumn::make('price_toman')->label('قیمت')
                    ->formatStateUsing(fn (int $state) => number_format($state) . ' تومان'),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->actions([EditAction::make()->label('ویرایش'), DeleteAction::make()->label('حذف')])
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
