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
use App\Support\Money;
use App\Filament\Resources\TokenPackageResource\Pages;

class TokenPackageResource extends Resource {
    protected static ?string $model = TokenPackage::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string { return __('plan.token_packages_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_finance'); }
    public static function getModelLabel(): string { return __('plan.token_package_singular'); }
    public static function getPluralModelLabel(): string { return __('plan.token_packages_nav'); }

    public static function canCreate(): bool { return true; }
    public static function canEdit($record): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            TextInput::make('name')->label(__('common.name'))->required()->maxLength(255)->placeholder(__('plan.token_package_name_placeholder')),
            Select::make('chatbot_type')
                ->label(__('chatbot.type'))
                ->options(array_combine(
                    array_map(fn ($c) => $c->value, ChatbotType::cases()),
                    array_map(fn ($c) => ucfirst($c->value), ChatbotType::cases()),
                ))
                ->placeholder(__('plan.any_type'))
                ->helperText(__('plan.token_package_type_help')),
            TextInput::make('token_amount')->label(__('plan.token_amount'))->numeric()->required(),
            TextInput::make('price_toman')->label(__('plan.price_toman'))->numeric()->required(),
            Toggle::make('is_active')->label(__('common.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('common.name')),
                TextColumn::make('chatbot_type')->label(__('chatbot.type'))->badge()->placeholder(__('plan.all_types')),
                TextColumn::make('token_amount')->label(__('plan.token_amount'))->formatStateUsing(fn (int $state) => number_format($state)),
                TextColumn::make('price_toman')->label(__('plan.price'))
                    ->formatStateUsing(fn (int $state) => Money::toman($state)),
                IconColumn::make('is_active')->label(__('common.active'))->boolean(),
            ])
            ->actions([EditAction::make()->label(__('common.edit')), DeleteAction::make()->label(__('common.delete'))])
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
