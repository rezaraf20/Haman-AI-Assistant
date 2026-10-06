<?php
namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Plan;
use App\Support\PlatformAccess;
use Filament\Forms\Components\{Grid, Repeater, Section, Textarea, TextInput, Toggle};
use Filament\Forms\Form;
use Filament\Forms\Get;
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
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_pricing'); }
    public static function getModelLabel(): string { return __('plans.singular'); }
    public static function getPluralModelLabel(): string { return __('plans.nav'); }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('plans.section_identity'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')->label(__('plans.name_fa'))->required()->maxLength(100),
                    TextInput::make('name_en')->label(__('plans.name_en'))->maxLength(100)
                        ->helperText(__('plans.name_en_help')),
                ]),
                TextInput::make('slug')->label(__('plans.slug'))->required()->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->helperText(__('plans.slug_help'))
                    ->columnSpanFull(),
                Grid::make(2)->schema([
                    Textarea::make('description')->label(__('plans.description_fa'))->rows(2)->maxLength(500),
                    Textarea::make('description_en')->label(__('plans.description_en'))->rows(2)->maxLength(500)
                        ->helperText(__('plans.name_en_help')),
                ]),
            ]),

            Section::make(__('plans.section_price'))
                ->description(__('plans.section_price_help'))
                ->schema([
                    TextInput::make('price_monthly')->label(__('plans.price_monthly'))
                        ->numeric()->minValue(0)->required()
                        ->live(onBlur: true)
                        ->hintIcon(fn (Get $get) => self::formPriceLooksDefault($get) ? 'heroicon-o-exclamation-triangle' : null)
                        ->hintColor('warning')
                        ->hint(fn (Get $get) => self::formPriceLooksDefault($get) ? __('plans.price_looks_default') : null),
                    // Defaults mirror the plans table's own column defaults
                    // (2025_01_01_000001_create_plans_table.php) — every one
                    // of these columns is NOT NULL. Filament submits an
                    // untouched numeric field as null, not "omitted", so
                    // without a matching ->default() here the DB's own
                    // default never gets a chance to apply and creating a
                    // plan with any of these left blank 500s instead of
                    // falling back sensibly.
                    TextInput::make('price_yearly')->label(__('plans.price_yearly'))
                        ->numeric()->minValue(0)->default(0),
                ])->columns(2),

            Section::make(__('plans.section_limits'))->schema([
                Grid::make(3)->schema([
                    TextInput::make('max_chatbots')->label(__('plans.max_chatbots'))->numeric()->minValue(0)->default(1),
                    TextInput::make('max_tokens_monthly')->label(__('plans.max_tokens'))->numeric()->minValue(0)->default(100000),
                    TextInput::make('max_documents')->label(__('plans.max_documents'))->numeric()->minValue(0)->default(50),
                    TextInput::make('max_messages_monthly')->label(__('plans.max_messages'))->numeric()->minValue(0)->default(1000),
                    TextInput::make('max_domains')->label(__('plans.max_domains'))->numeric()->minValue(0)->default(1),
                    TextInput::make('model_tier')->label(__('plans.model_tier'))->maxLength(50)->default('gemini-1.5-flash'),
                ]),
                Repeater::make('features')
                    ->label(__('plans.features'))
                    ->helperText(__('plans.features_help'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('fa')->label(__('plans.features_fa'))->required()->maxLength(150),
                            TextInput::make('en')->label(__('plans.features_en'))->maxLength(150),
                        ]),
                    ])
                    ->addActionLabel(__('plans.features_add'))
                    ->reorderable()
                    ->collapsible()
                    // Filament's own default is 1 — a brand-new plan would
                    // otherwise always start with one required-but-blank
                    // feature row, forcing something to be typed in even
                    // when the plan genuinely has nothing to list yet.
                    ->defaultItems(0)
                    ->columnSpanFull(),
            ]),

            Section::make(__('plans.section_tools'))
                ->description(__('plans.section_tools_help'))
                ->schema([
                    // Options come from ChatbotTools::names() — the live
                    // Python tool registry, via that same catalogue class —
                    // not a list typed out here, so a new tool the registry
                    // gains shows up as a checkbox automatically instead of
                    // needing a second place updated to grant any plan
                    // access to it.
                    \Filament\Forms\Components\CheckboxList::make('allowed_tools')
                        ->label('')
                        ->options(collect(\App\Support\ChatbotTools::names())
                            ->mapWithKeys(fn ($name) => [$name => __('chatbot.tool_' . $name)])
                            ->all())
                        ->columns(2)
                        ->bulkToggleable(),
                ]),

            Section::make(__('plans.section_behavior'))->schema([
                Toggle::make('can_purchase_tokens')->label(__('plans.can_purchase_tokens'))
                    ->helperText(__('plans.can_purchase_tokens_help'))->default(true),
                Toggle::make('branding_removable')->label(__('plans.branding_removable'))
                    ->helperText(__('plans.branding_removable_help'))->default(false),
                \Filament\Forms\Components\Select::make('quota_exceeded_behavior')
                    ->label(__('plans.quota_exceeded_behavior'))
                    ->helperText(__('plans.quota_exceeded_behavior_help'))
                    ->options([
                        'stop' => __('plans.quota_behavior_stop'),
                        'degrade' => __('plans.quota_behavior_degrade'),
                        'auto_wallet' => __('plans.quota_behavior_auto_wallet'),
                    ])
                    ->default('auto_wallet')->required(),
            ])->columns(3),

            Section::make(__('plans.section_visibility'))->schema([
                Toggle::make('is_active')->label(__('plans.is_active'))
                    ->helperText(__('plans.is_active_help'))->default(true)->live(),
                // Deliberately separate from is_active: the free plan every
                // signup lands on is active without being something to
                // advertise, and a plan agreed with one customer is active
                // and definitely is not.
                Toggle::make('is_public')->label(__('plans.is_public'))
                    ->helperText(__('plans.is_public_help'))->default(false)->live(),
                TextInput::make('sort_order')->label(__('plans.sort_order'))->numeric()->default(0)
                    ->helperText(__('plans.sort_order_help')),
            ])->columns(3),
        ]);
    }

    /** Mirrors Plan::looksLikeDefaultPrice() against live (possibly unsaved) form state, since a new/edited record has no $record to call that on yet. */
    private static function formPriceLooksDefault(Get $get): bool {
        return (bool) $get('is_active') && (bool) $get('is_public')
            && (float) $get('price_monthly') > 0
            && (float) $get('price_monthly') < Plan::PRICE_SANITY_THRESHOLD;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('name')->label(__('common.name'))->searchable(),
                Tables\Columns\TextColumn::make('price_monthly')->label(__('plans.price_monthly'))->sortable()
                    ->icon(fn (Plan $record) => $record->looksLikeDefaultPrice() ? 'heroicon-o-exclamation-triangle' : null)
                    ->iconColor('warning')
                    ->tooltip(fn (Plan $record) => $record->looksLikeDefaultPrice() ? __('plans.price_looks_default') : null),
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
