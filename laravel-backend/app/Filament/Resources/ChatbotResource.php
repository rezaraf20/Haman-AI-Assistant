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
use App\Support\DomainNormalizer;
use App\Filament\Resources\ChatbotResource\Pages;

class ChatbotResource extends Resource {
    protected static ?string $model = ChatbotIndexEntry::class;
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'چت‌بات‌ها';
    protected static ?string $modelLabel = 'چت‌بات';
    protected static ?string $pluralModelLabel = 'چت‌بات‌ها';

    // See TenantResource — no per-model Policy is registered, and Filament's
    // action visibility otherwise silently hides Create/Edit/Delete without one.
    public static function canCreate(): bool { return true; }
    public static function canEdit($record): bool { return true; }
    public static function canDelete($record): bool { return true; }

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('tenant_id')
                ->label('مشتری')
                ->options(fn () => Tenant::orderBy('name')->pluck('name', 'id'))
                ->searchable()->required()
                ->visibleOn('create'),
            Select::make('type')
                ->label('نوع')
                ->options(array_combine(
                    array_map(fn ($c) => $c->value, ChatbotType::cases()),
                    array_map(fn ($c) => ucfirst($c->value), ChatbotType::cases()),
                ))
                ->required()
                ->visibleOn('create'),
            TextInput::make('name')->label('نام')->required()->maxLength(255),
            TextInput::make('primary_domain')->label('دامنه')->maxLength(255)
                ->dehydrateStateUsing(fn ($state) => DomainNormalizer::normalize($state))
                ->helperText('دامنه‌ی مجاز ویجت — اگر خالی باشد هیچ محدودیتی اعمال نمی‌شود.'),
            DateTimePicker::make('expires_at')->label('تاریخ تمدید / انقضا')
                ->helperText('اگه خالی بذاری، این چت‌بات هیچ‌وقت خودکار معلق نمی‌شه. جاب روزانه‌ی chatbots:expire-overdue بعد از گذشتن این تاریخ معلقش می‌کنه.'),
            TextInput::make('monthly_price_toman')
                ->label('هزینه‌ی تمدید ماهانه (تومان)')
                ->numeric()
                ->default(0)
                ->required()
                ->helperText('مبلغی که مشتری از کیف پول خودش، توی پرتال خودش، برای تمدید ماهانه‌ی همین یک چت‌بات پرداخت می‌کنه.'),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('name')->label('نام')->searchable()->sortable()->default('(بدون نام)'),
                TextColumn::make('primary_domain')->label('دامنه')->searchable(),
                TextColumn::make('tenant.name')->label('مشتری')->searchable(),
                IconColumn::make('is_active')->boolean()->label('فعال'),
                TextColumn::make('expires_at')
                    ->label('انقضا')
                    ->formatStateUsing(fn ($state) => Jalali::dateTime($state))
                    ->sortable()
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null)
                    ->placeholder('نامحدود'),
                TextColumn::make('monthly_price_toman')->label('هزینه/ماه')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? number_format($state) . ' ت' : '—'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('فعال'),
                Filter::make('overdue')
                    ->label('منقضی‌شده (هنوز فعاله)')
                    ->query(fn ($query) => $query->where('is_active', true)->whereNotNull('expires_at')->where('expires_at', '<', now())),
            ])
            ->actions([
                Action::make('suspend')
                    ->label('معلق کردن')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (ChatbotIndexEntry $record) => $record->is_active)
                    ->requiresConfirmation()
                    ->modalDescription('این کار بلافاصله ویجت چت عمومی و سینک پلاگین وردپرس این چت‌بات رو مسدود می‌کنه.')
                    ->action(function (ChatbotIndexEntry $record) {
                        $record->update(['is_active' => false]);
                        Notification::make()->title("{$record->name} معلق شد")->success()->send();
                    }),
                Action::make('reactivate')
                    ->label('فعال‌سازی مجدد')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ChatbotIndexEntry $record) => !$record->is_active)
                    ->action(function (ChatbotIndexEntry $record) {
                        $record->update(['is_active' => true]);
                        Notification::make()->title("{$record->name} دوباره فعال شد")->success()->send();
                    }),
                Action::make('edit')->label('ویرایش')->url(fn (ChatbotIndexEntry $record) => static::getUrl('edit', ['record' => $record])),
                Action::make('delete')
                    ->label('حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ChatbotIndexEntry $record) => "حذف {$record->name}؟")
                    ->modalDescription('این چت‌بات و هر کلید API متصل بهش رو برای همیشه پاک می‌کنه. اسناد/مکالمات/پیام‌هاش توی دیتابیس تننت باقی می‌مونن (فقط خود ردیف چت‌بات حذف می‌شه) ولی دیگه در دسترس نیست. غیرقابل بازگشته.')
                    ->action(function (ChatbotIndexEntry $record) {
                        $chatbotId = $record->chatbot_id;
                        $schema    = $record->schema_name;

                        ApiKey::where('chatbot_id', $chatbotId)->delete();

                        DB::statement("SET search_path TO {$schema}, public");
                        DB::table('chatbots')->where('id', $chatbotId)->delete();
                        DB::statement("SET search_path TO public");

                        $record->delete();

                        Notification::make()->title('چت‌بات حذف شد')->success()->send();
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
