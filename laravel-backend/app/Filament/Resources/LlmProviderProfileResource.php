<?php
namespace App\Filament\Resources;

use App\Models\LlmProviderProfile;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, Toggle};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\{DeleteAction, EditAction};
use App\Support\Jalali;
use App\Filament\Resources\LlmProviderProfileResource\Pages;

class LlmProviderProfileResource extends Resource {
    protected static ?string $model = LlmProviderProfile::class;
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string { return __('panel.llm_providers_nav'); }
    public static function getModelLabel(): string { return __('panel.llm_provider_singular'); }
    public static function getPluralModelLabel(): string { return __('panel.llm_providers_nav'); }

    public static function canCreate(): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            TextInput::make('name')->label(__('common.name'))->required()->maxLength(255)
                ->placeholder(__('panel.provider_name_placeholder')),
            Select::make('provider')
                ->label(__('panel.provider'))
                ->options([
                    'groq'              => 'Groq',
                    'xai'               => 'xAI',
                    'openai_compatible' => __('panel.provider_other'),
                ])
                ->required()
                ->live(),
            TextInput::make('base_url')
                ->label(__('panel.base_url'))
                ->url()
                ->required(fn ($get) => $get('provider') === 'openai_compatible')
                ->helperText(__('panel.base_url_help')),
            TextInput::make('model_name')->label(__('panel.model_name'))->required()->maxLength(100)
                ->placeholder(__('panel.model_name_placeholder')),
            TextInput::make('api_key')->label(__('panel.api_key'))->password()->revealable()
                ->required(fn (string $context) => $context === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText(__('panel.api_key_help')),
            TextInput::make('input_price_per_1m_toman')->label(__('panel.input_price'))
                ->numeric()->default(0)->required()
                ->helperText(__('panel.input_price_help')),
            TextInput::make('output_price_per_1m_toman')->label(__('panel.output_price'))
                ->numeric()->default(0)->required(),
            TextInput::make('priority')->label(__('panel.priority'))->numeric()->default(0)->required()
                ->helperText(__('panel.priority_help')),
            Toggle::make('is_active')->label(__('common.active'))->default(true),
            TextInput::make('max_tokens_response')->label(__('panel.max_tokens_response'))->numeric()->nullable(),
            TextInput::make('timeout_seconds')->label(__('panel.timeout_seconds'))->numeric()->default(30)->required(),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('priority')->label('#')->sortable(),
                TextColumn::make('name')->label(__('common.name'))->searchable(),
                TextColumn::make('provider')->label(__('panel.provider'))->badge(),
                TextColumn::make('model_name')->label(__('panel.model_name')),
                TextColumn::make('api_key')
                    ->label(__('panel.api_key'))
                    ->formatStateUsing(fn (?string $state) => $state ? str_repeat('•', 8) . substr($state, -4) : '—'),
                TextColumn::make('input_price_per_1m_toman')->label(__('panel.input_price_short'))
                    ->formatStateUsing(fn ($state) => number_format($state) . ' ' . __('common.toman_short'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('output_price_per_1m_toman')->label(__('panel.output_price_short'))
                    ->formatStateUsing(fn ($state) => number_format($state) . ' ' . __('common.toman_short'))->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean()->label(__('common.active')),
                TextColumn::make('consecutive_failures')->label(__('panel.consecutive_failures'))
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('last_success_at')->label(__('panel.last_success'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder(__('common.never'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_failure_at')->label(__('panel.last_failure'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder(__('common.never'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->reorderable('priority')
            ->defaultSort('priority')
            ->actions([
                EditAction::make()->label(__('common.edit')),
                DeleteAction::make()->label(__('common.delete')),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListLlmProviderProfiles::route('/'),
            'create' => Pages\CreateLlmProviderProfile::route('/create'),
            'edit'   => Pages\EditLlmProviderProfile::route('/{record}/edit'),
        ];
    }
}
