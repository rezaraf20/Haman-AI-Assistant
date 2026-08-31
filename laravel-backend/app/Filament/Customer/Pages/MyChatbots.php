<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotIndexEntry;
use App\Services\WalletService;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\{TextColumn, IconColumn};
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use App\Support\Jalali;

class MyChatbots extends Page implements HasTable {
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.my-chatbots';
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'چت‌بات‌های من';
    protected static ?string $title = 'چت‌بات‌های من';

    public function table(Table $table): Table {
        return $table
            ->query(fn () => ChatbotIndexEntry::query()->where('tenant_id', auth()->user()->tenant_id))
            ->columns([
                TextColumn::make('name')->label('نام')->default('(بدون نام)'),
                TextColumn::make('primary_domain')->label('دامنه')->placeholder('—'),
                IconColumn::make('is_active')->boolean()->label('فعال'),
                TextColumn::make('expires_at')->label('تاریخ انقضا')->formatStateUsing(fn ($state) => Jalali::date($state))
                    ->placeholder('نامحدود')
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null),
                TextColumn::make('monthly_price_toman')->label('هزینه تمدید ماهانه')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? number_format($state) . ' تومان' : 'تماس با پشتیبانی'),
            ])
            ->actions([
                Action::make('renew')
                    ->label('تمدید از کیف پول')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (ChatbotIndexEntry $record) => $record->monthly_price_toman > 0)
                    ->requiresConfirmation()
                    ->modalDescription(fn (ChatbotIndexEntry $record) => "مبلغ {$this->formatToman($record->monthly_price_toman)} از کیف پول شما کسر می‌شود و یک ماه به تاریخ انقضا اضافه می‌شود.")
                    ->action(fn (ChatbotIndexEntry $record) => $this->renew($record)),
            ]);
    }

    private function formatToman(int $amount): string {
        return number_format($amount) . ' تومان';
    }

    private function renew(ChatbotIndexEntry $record): void {
        $tenant = auth()->user()->tenant;
        $price  = $record->monthly_price_toman;

        if ($tenant->wallet_balance_toman < $price) {
            Notification::make()
                ->title('موجودی کیف پول کافی نیست')
                ->body('لطفاً ابتدا کیف پول خود را شارژ کنید.')
                ->danger()
                ->send();
            return;
        }

        app(WalletService::class)->applyCompletedTransaction(
            $tenant,
            'plan_charge',
            -$price,
            ['description' => "تمدید چت‌بات: {$record->name}"],
        );

        $base = ($record->expires_at && $record->expires_at->isFuture()) ? $record->expires_at : now();
        $record->update([
            'expires_at' => $base->copy()->addMonth(),
            'is_active'  => true,
        ]);

        Notification::make()->title('تمدید با موفقیت انجام شد')->success()->send();
    }
}
