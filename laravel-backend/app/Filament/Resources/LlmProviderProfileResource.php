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
    protected static ?string $navigationLabel = 'مدل‌های هوش مصنوعی';
    protected static ?string $modelLabel = 'مدل';
    protected static ?string $pluralModelLabel = 'مدل‌ها';

    public static function canCreate(): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            TextInput::make('name')->label('نام')->required()->maxLength(255)
                ->placeholder('مثلاً «Groq (llama-3.1-8b-instant)»'),
            Select::make('provider')
                ->label('ارائه‌دهنده')
                ->options([
                    'groq'              => 'Groq',
                    'xai'               => 'xAI',
                    'openai_compatible' => 'سایر (سازگار با OpenAI)',
                ])
                ->required()
                ->live(),
            TextInput::make('base_url')
                ->label('آدرس پایه (Base URL)')
                ->url()
                ->required(fn ($get) => $get('provider') === 'openai_compatible')
                ->helperText('مثلاً https://api.groq.com/openai/v1 — برای «سایر» اجباریه، برای بقیه اختیاریه.'),
            TextInput::make('model_name')->label('مدل')->required()->maxLength(100)
                ->placeholder('مثلاً llama-3.1-8b-instant'),
            TextInput::make('api_key')->label('کلید API')->password()->revealable()
                ->required(fn (string $context) => $context === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('خالی گذاشتن در ویرایش = کلید فعلی حفظ می‌شه.'),
            TextInput::make('input_price_per_1m_toman')->label('قیمت هر ۱ میلیون توکن ورودی (تومان)')
                ->numeric()->default(0)->required()
                ->helperText('برای محاسبه‌ی هزینه‌ی واقعی هر پیام — قیمتی که ارائه‌دهنده از شما می‌گیره رو به تومان تبدیل کنید.'),
            TextInput::make('output_price_per_1m_toman')->label('قیمت هر ۱ میلیون توکن خروجی (تومان)')
                ->numeric()->default(0)->required(),
            TextInput::make('priority')->label('اولویت')->numeric()->default(0)->required()
                ->helperText('عدد کمتر یعنی زودتر امتحان می‌شه.'),
            Toggle::make('is_active')->label('فعال')->default(true),
            TextInput::make('max_tokens_response')->label('حداکثر توکن پاسخ')->numeric()->nullable(),
            TextInput::make('timeout_seconds')->label('مهلت زمانی (ثانیه)')->numeric()->default(30)->required(),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('priority')->label('#')->sortable(),
                TextColumn::make('name')->label('نام')->searchable(),
                TextColumn::make('provider')->label('ارائه‌دهنده')->badge(),
                TextColumn::make('model_name')->label('مدل'),
                TextColumn::make('api_key')
                    ->label('کلید API')
                    ->formatStateUsing(fn (?string $state) => $state ? str_repeat('•', 8) . substr($state, -4) : '—'),
                TextColumn::make('input_price_per_1m_toman')->label('قیمت ورودی/۱M')
                    ->formatStateUsing(fn ($state) => number_format($state) . ' ت')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('output_price_per_1m_toman')->label('قیمت خروجی/۱M')
                    ->formatStateUsing(fn ($state) => number_format($state) . ' ت')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean()->label('فعال'),
                TextColumn::make('consecutive_failures')->label('شکست‌ها')
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('last_success_at')->label('آخرین موفقیت')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder('هیچ‌وقت')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_failure_at')->label('آخرین شکست')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder('هیچ‌وقت')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->reorderable('priority')
            ->defaultSort('priority')
            ->actions([
                EditAction::make()->label('ویرایش'),
                DeleteAction::make()->label('حذف'),
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
