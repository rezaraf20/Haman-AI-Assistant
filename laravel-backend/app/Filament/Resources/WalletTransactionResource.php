<?php
namespace App\Filament\Resources;

use App\Models\WalletTransaction;
use App\Models\Tenant;
use App\Services\WalletService;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, Textarea};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, BadgeColumn};
use Filament\Tables\Filters\SelectFilter;
use App\Support\Jalali;
use App\Filament\Resources\WalletTransactionResource\Pages;

class WalletTransactionResource extends Resource {
    protected static ?string $model = WalletTransaction::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'تراکنش‌های کیف پول';
    protected static ?string $modelLabel = 'تراکنش';
    protected static ?string $pluralModelLabel = 'تراکنش‌ها';

    // Only "create" is allowed here (for manual admin adjustments), via the
    // WalletService ledger helper — no Edit/Delete: transactions are an
    // append-only audit trail, not a table you correct in place.
    public static function canCreate(): bool { return true; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function form(Form $form): Form {
        return $form->schema([
            Select::make('tenant_id')
                ->label('مشتری')
                ->options(fn () => Tenant::pluck('name', 'id'))
                ->searchable()
                ->required(),
            TextInput::make('amount_toman')
                ->label('مبلغ (تومان)')
                ->numeric()
                ->required()
                ->helperText('برای شارژ عدد مثبت، برای کسر عدد منفی وارد کنید.'),
            Textarea::make('description')->label('توضیحات')->required()->placeholder('دلیل این تغییر دستی (مثلاً تخفیف یا واریز کارت‌به‌کارت)'),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('تاریخ')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
                TextColumn::make('tenant.name')->label('مشتری')->searchable(),
                BadgeColumn::make('type')->label('نوع')->colors([
                    'success' => 'topup',
                    'danger'  => 'plan_charge',
                    'warning' => 'admin_adjustment',
                    'gray'    => 'refund',
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'topup' => 'شارژ', 'plan_charge' => 'کسر بابت خرید', 'admin_adjustment' => 'تنظیم دستی', 'refund' => 'بازگشت وجه', default => $state,
                }),
                TextColumn::make('amount_toman')->label('مبلغ')
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('balance_after_toman')->label('موجودی پس از تراکنش')
                    ->formatStateUsing(fn (int $state) => number_format($state)),
                BadgeColumn::make('status')->label('وضعیت')->colors([
                    'success' => 'completed',
                    'warning' => 'pending',
                    'danger'  => ['failed', 'reversed'],
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'completed' => 'موفق', 'pending' => 'در انتظار', 'failed' => 'ناموفق', 'reversed' => 'برگشت‌خورده', default => $state,
                }),
                TextColumn::make('gateway')->label('درگاه')->placeholder('—'),
                TextColumn::make('gateway_ref_id')->label('شماره پیگیری')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')->label('توضیحات')->limit(40),
            ])
            ->filters([
                SelectFilter::make('status')->label('وضعیت')->options([
                    'pending' => 'در انتظار', 'completed' => 'موفق', 'failed' => 'ناموفق', 'reversed' => 'برگشت‌خورده',
                ]),
                SelectFilter::make('type')->label('نوع')->options([
                    'topup' => 'شارژ', 'plan_charge' => 'کسر بابت خرید', 'admin_adjustment' => 'تنظیم دستی', 'refund' => 'بازگشت وجه',
                ]),
                SelectFilter::make('tenant_id')->relationship('tenant', 'name')->label('مشتری')->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListWalletTransactions::route('/'),
            'create' => Pages\CreateWalletTransaction::route('/create'),
        ];
    }
}
