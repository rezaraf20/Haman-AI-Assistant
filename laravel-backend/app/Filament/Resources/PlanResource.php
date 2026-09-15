<?php
namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Plan;
use App\Support\PlatformAccess;
use Filament\Forms\Components\{Grid, Section, TagsInput, Textarea, TextInput, Toggle};
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Plans, and the numbers the public pricing table shows.
 *
 * The plans existed and drove real billing, but nothing in the panel could
 * edit them — so the prices on the new landing page would have been a
 * hardcoded Blade table drifting away from what customers are actually
 * charged. They are edited here instead, and the site reads the same rows.
 *
 * Pricing is admin business, not support's.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool { return PlatformAccess::allows('pricing'); }
    public static function canView($record): bool { return PlatformAccess::allows('pricing'); }
    public static function canCreate(): bool { return PlatformAccess::allows('pricing'); }
    public static function canEdit($record): bool { return PlatformAccess::allows('pricing'); }
    public static function canDelete($record): bool { return PlatformAccess::allows('pricing'); }
    public static function canDeleteAny(): bool { return PlatformAccess::allows('pricing'); }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('pricing'); }

    public static function getNavigationLabel(): string { return __('plans.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_infrastructure'); }
    public static function getModelLabel(): string { return __('plans.singular'); }
    public static function getPluralModelLabel(): string { return __('plans.nav'); }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('plans.section_identity'))->schema([
                TextInput::make('name')->label(__('common.name'))->required()->maxLength(100),
                TextInput::make('slug')->label(__('plans.slug'))->required()->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->helperText(__('plans.slug_help')),
                Textarea::make('description')->label(__('plans.description'))->rows(2)->maxLength(500)
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make(__('plans.section_price'))
                ->description(__('plans.section_price_help'))
                ->schema([
                    TextInput::make('price_monthly')->label(__('plans.price_monthly'))
                        ->numeric()->minValue(0)->required(),
                    TextInput::make('price_yearly')->label(__('plans.price_yearly'))
                        ->numeric()->minValue(0),
                ])->columns(2),

            Section::make(__('plans.section_limits'))->schema([
                Grid::make(3)->schema([
                    TextInput::make('max_chatbots')->label(__('plans.max_chatbots'))->numeric()->minValue(0),
                    TextInput::make('max_tokens_monthly')->label(__('plans.max_tokens'))->numeric()->minValue(0),
                    TextInput::make('max_documents')->label(__('plans.max_documents'))->numeric()->minValue(0),
                    TextInput::make('max_messages_monthly')->label(__('plans.max_messages'))->numeric()->minValue(0),
                    TextInput::make('max_domains')->label(__('plans.max_domains'))->numeric()->minValue(0),
                    TextInput::make('model_tier')->label(__('plans.model_tier'))->maxLength(50),
                ]),
                TagsInput::make('features')
                    ->label(__('plans.features'))
                    ->helperText(__('plans.features_help'))
                    ->columnSpanFull(),
            ]),

            Section::make(__('plans.section_visibility'))->schema([
                Toggle::make('is_active')->label(__('plans.is_active'))
                    ->helperText(__('plans.is_active_help'))->default(true),
                // Deliberately separate from is_active: the free plan every
                // signup lands on is active without being something to
                // advertise, and a plan agreed with one customer is active
                // and definitely is not.
                Toggle::make('is_public')->label(__('plans.is_public'))
                    ->helperText(__('plans.is_public_help'))->default(false),
                TextInput::make('sort_order')->label(__('plans.sort_order'))->numeric()->default(0)
                    ->helperText(__('plans.sort_order_help')),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('name')->label(__('common.name'))->searchable(),
                Tables\Columns\TextColumn::make('price_monthly')->label(__('plans.price_monthly'))->sortable(),
                Tables\Columns\TextColumn::make('max_chatbots')->label(__('plans.max_chatbots')),
                Tables\Columns\TextColumn::make('max_tokens_monthly')->label(__('plans.max_tokens'))
                    ->formatStateUsing(fn ($state) => number_format((int) $state)),
                Tables\Columns\TextColumn::make('tenants_count')->label(__('plans.tenants'))
                    ->counts('tenants'),
                Tables\Columns\IconColumn::make('is_active')->label(__('plans.is_active'))->boolean(),
                Tables\Columns\IconColumn::make('is_public')->label(__('plans.is_public'))->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit'   => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
