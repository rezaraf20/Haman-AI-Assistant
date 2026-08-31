<?php
namespace App\Filament\Resources;

use App\Models\TokenPackage;
use App\Enums\ChatbotType;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, Toggle};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\{EditAction, DeleteAction};
use App\Filament\Resources\TokenPackageResource\Pages;

class TokenPackageResource extends Resource {
    protected static ?string $model = TokenPackage::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?int $navigationSort = 7;
    protected static ?string $navigationLabel = 'بسته‌های توکن';
    protected static ?string $modelLabel = 'بسته توکن';
    protected static ?string $pluralModelLabel = 'بسته‌های توکن';

    public static function canCreate(): bool { return true; }
    public static function canEdit($record): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            TextInput::make('name')->label('نام')->required()->maxLength(255)->placeholder('مثلاً «۱ میلیون توکن اضافه»'),
            Select::make('chatbot_type')
                ->label('نوع چت‌بات')
                ->options(array_combine(
                    array_map(fn ($c) => $c->value, ChatbotType::cases()),
                    array_map(fn ($c) => ucfirst($c->value), ChatbotType::cases()),
                ))
                ->placeholder('هر نوعی')
                ->helperText('برای ارائه‌ی این بسته به همه‌ی انواع چت‌بات، خالی بگذارید.'),
            TextInput::make('token_amount')->label('تعداد توکن')->numeric()->required(),
            TextInput::make('price_toman')->label('قیمت (تومان)')->numeric()->required(),
            Toggle::make('is_active')->label('فعال')->default(true),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('name')->label('نام'),
                TextColumn::make('chatbot_type')->label('نوع چت‌بات')->badge()->placeholder('همه'),
                TextColumn::make('token_amount')->label('تعداد توکن')->formatStateUsing(fn (int $state) => number_format($state)),
                TextColumn::make('price_toman')->label('قیمت')
                    ->formatStateUsing(fn (int $state) => number_format($state) . ' تومان'),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->actions([EditAction::make()->label('ویرایش'), DeleteAction::make()->label('حذف')])
            ->defaultSort('price_toman');
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListTokenPackages::route('/'),
            'create' => Pages\CreateTokenPackage::route('/create'),
            'edit'   => Pages\EditTokenPackage::route('/{record}/edit'),
        ];
    }
}
