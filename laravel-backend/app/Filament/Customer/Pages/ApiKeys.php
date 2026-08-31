<?php
namespace App\Filament\Customer\Pages;

use App\Models\ApiKey;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Actions\Action as FieldAction;
use App\Support\Jalali;

// Until now the only place a customer ever saw their full API key was a
// one-time reveal modal right after buying a chatbot (BuyChatbot::purchase())
// — lose that copy and the only fix was a support ticket. This page lets them
// pull any of their keys back up on demand, the same way the admin panel now
// can (see ApiKeyResource's 'showKey' action).
class ApiKeys extends Page implements HasTable {
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.api-keys';
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'کلیدهای API';
    protected static ?string $title = 'کلیدهای API من';

    public function table(Table $table): Table {
        return $table
            ->query(fn () => ApiKey::query()->where('tenant_id', auth()->user()->tenant_id))
            ->columns([
                TextColumn::make('name')->label('نام'),
                TextColumn::make('chatbotIndexEntry.name')->label('چت‌بات')->default('—'),
                TextColumn::make('chatbotIndexEntry.primary_domain')->label('دامنه')->default('—'),
                TextColumn::make('key_prefix')->label('پیشوند')->fontFamily('mono'),
                IconColumn::make('is_active')->boolean()->label('فعال'),
                TextColumn::make('expires_at')->label('انقضا')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->placeholder('نامحدود'),
                TextColumn::make('created_at')->label('تاریخ ساخت')->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
            ])
            ->actions([
                Action::make('showKey')
                    ->label('نمایش کلید')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('کلید API کامل')
                    ->modalDescription(fn (ApiKey $record) => $record->revealKey()
                        ? 'این کلید را در تنظیمات افزونه‌ی وردپرس خود وارد کنید. آن را در جای امنی نگه دارید.'
                        : 'این کلید قدیمی است و دیگر قابل نمایش مجدد نیست — از پشتیبانی بخواهید کلید جدیدی برایتان بسازد.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('بستن')
                    ->form(fn (ApiKey $record) => $record->revealKey() ? [
                        TextInput::make('key')
                            ->label('کلید API')
                            ->default(fn () => $record->revealKey())
                            ->readOnly()
                            ->extraInputAttributes(['class' => 'font-mono text-sm', 'id' => "hamman-cust-key-{$record->id}"])
                            ->suffixActions([
                                FieldAction::make('copy')
                                    ->icon('heroicon-m-clipboard')
                                    ->label('کپی')
                                    ->alpineClickHandler(
                                        "navigator.clipboard.writeText(document.getElementById('hamman-cust-key-{$record->id}').value)"
                                    ),
                            ]),
                    ] : []),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
