<?php
namespace App\Filament\Resources;

use App\Models\Tenant;
use App\Models\Plan;
<<<<<<< HEAD
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select};
=======
use App\Models\User;
use App\Models\ApiKey;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, Section, Textarea};
>>>>>>> origin/develop
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, BadgeColumn};
use Filament\Tables\Filters\SelectFilter;
<<<<<<< HEAD
=======
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use App\Support\Jalali;
>>>>>>> origin/develop
use App\Filament\Resources\TenantResource\Pages;

class TenantResource extends Resource {
    protected static ?string $model = Tenant::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?int $navigationSort = 1;
<<<<<<< HEAD

    public static function form(Form $form): Form {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255),
            TextInput::make('phone')->maxLength(50),
            Select::make('plan_id')->relationship('plan', 'name')->searchable(),
            Select::make('status')->options([
                'trial' => 'Trial',
                'active' => 'Active',
                'suspended' => 'Suspended',
                'cancelled' => 'Cancelled',
            ])->required(),
=======
    protected static ?string $navigationLabel = 'مشتریان';
    protected static ?string $modelLabel = 'مشتری';
    protected static ?string $pluralModelLabel = 'مشتریان';

    // Only 'owner'-role users reach this panel at all (see AdminPanelProvider /
    // User::canAccessPanel()) — no per-model Policy is registered, and Filament's
    // action visibility otherwise silently hides Create/Edit/Delete without one.
    public static function canCreate(): bool { return true; }
    public static function canEdit($record): bool { return true; }
    public static function canDelete($record): bool { return true; }

    // Cleared in ListTenants::mount() the first time the admin opens this
    // list — see that file for why a bulk "mark all visible as seen" fits
    // this resource better than per-record tracking (unlike tickets, there's
    // no natural single-record "open" action a customer row funnels through).
    public static function getNavigationBadge(): ?string {
        $count = Tenant::whereNull('admin_seen_at')->count();
        return $count > 0 ? (string) $count : null;
    }
    public static function getNavigationBadgeColor(): ?string { return 'success'; }

    public static function form(Form $form): Form {
        return $form->schema([
            TextInput::make('name')->label('نام')->required()->maxLength(255),
            TextInput::make('email')->label('ایمیل')->email()->required()->maxLength(255)
                ->unique(table: 'tenants', column: 'email', ignoreRecord: true),
            TextInput::make('password')
                ->label('رمز عبور')
                ->password()->revealable()->required()->minLength(8)
                ->visibleOn('create')
                ->helperText('رمز عبور ورود این مشتری به پنل خودش.'),
            TextInput::make('phone')->label('تلفن')->maxLength(50),
            Select::make('plan_id')->label('پلن')->relationship('plan', 'name')->searchable(),
            Select::make('status')->label('وضعیت')->options([
                'trial' => 'آزمایشی',
                'active' => 'فعال',
                'suspended' => 'معلق',
                'cancelled' => 'لغوشده',
            ])->required(),
            // Read-only — these live on the owning User row (phone-based
            // signup profile), not on Tenant itself, so they're populated via
            // afterStateHydrated() rather than a normal bound field. disabled()
            // fields aren't submitted unless explicitly dehydrated(), so this
            // is display-only and can't accidentally overwrite user data.
            Section::make('اطلاعات مالک حساب')
                ->schema([
                    TextInput::make('owner_national_id')->label('کد ملی')->disabled()
                        ->afterStateHydrated(fn ($component, $record) => $component->state($record?->owner?->national_id)),
                    Textarea::make('owner_address')->label('آدرس')->disabled()->rows(2)
                        ->afterStateHydrated(fn ($component, $record) => $component->state($record?->owner?->address)),
                ])
                ->visibleOn('edit'),
>>>>>>> origin/develop
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
<<<<<<< HEAD
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('plan.name')->label('Plan')->badge(),
                BadgeColumn::make('status')->colors([
                    'success' => 'active',
                    'warning' => 'trial',
                    'danger'  => ['suspended', 'cancelled'],
                ]),
                TextColumn::make('trial_ends_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'trial' => 'Trial', 'active' => 'Active', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled',
                ]),
                SelectFilter::make('plan_id')->relationship('plan', 'name')->label('Plan'),
=======
                TextColumn::make('name')->label('نام')->searchable()->sortable(),
                TextColumn::make('email')->label('ایمیل')->searchable(),
                TextColumn::make('phone')->label('تلفن')->searchable()->placeholder('—'),
                TextColumn::make('owner.national_id')->label('کد ملی')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('owner.address')->label('آدرس')->limit(30)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plan.name')->label('پلن')->badge(),
                BadgeColumn::make('status')->label('وضعیت')->colors([
                    'success' => 'active',
                    'warning' => 'trial',
                    'danger'  => ['suspended', 'cancelled'],
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'trial' => 'آزمایشی', 'active' => 'فعال', 'suspended' => 'معلق', 'cancelled' => 'لغوشده', default => $state,
                }),
                TextColumn::make('wallet_balance_toman')->label('کیف پول')
                    ->formatStateUsing(fn (int $state) => number_format($state) . ' تومان')
                    ->sortable(),
                TextColumn::make('usage_tokens_current')->label('مصرف توکن')
                    ->formatStateUsing(fn ($record) => number_format($record->usage_tokens_current) . ' / ' . ($record->plan?->max_tokens_monthly ? number_format($record->plan->max_tokens_monthly) : '∞'))
                    ->color(fn ($record) => $record->isTokenQuotaExceeded() ? 'danger' : null),
                TextColumn::make('trial_ends_at')->label('پایان آزمایشی')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
                TextColumn::make('created_at')->label('تاریخ ثبت')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('وضعیت')->options([
                    'trial' => 'آزمایشی', 'active' => 'فعال', 'suspended' => 'معلق', 'cancelled' => 'لغوشده',
                ]),
                SelectFilter::make('plan_id')->relationship('plan', 'name')->label('پلن'),
            ])
            ->actions([
                Action::make('delete')
                    ->label('حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Tenant $record) => "حذف کامل {$record->name}؟")
                    ->modalDescription('این کار کل دیتابیس این مشتری — همه‌ی چت‌بات‌ها، اسناد، مکالمات و پیام‌ها — به‌همراه حساب ورود و کلیدهای API‌اش رو برای همیشه پاک می‌کنه. غیرقابل بازگشته. اگه ممکنه بعداً به این داده‌ها نیاز داشته باشی، به‌جاش «معلق» کن.')
                    ->modalSubmitActionLabel('بله، همه‌چیز حذف شود')
                    ->action(function (Tenant $record) {
                        $schema = $record->schema_name;
                        $id     = $record->id;

                        ApiKey::where('tenant_id', $id)->delete();
                        User::where('tenant_id', $id)->delete();
                        DB::table('chatbot_index')->where('tenant_id', $id)->delete();
                        DB::statement("SET search_path TO public");
                        DB::statement("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");

                        $name = $record->name;
                        $record->delete();

                        Notification::make()->title("{$name} و کل دیتابیسش حذف شد")->success()->send();
                    }),
>>>>>>> origin/develop
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array {
        return [
<<<<<<< HEAD
            'index' => Pages\ListTenants::route('/'),
            'edit'  => Pages\EditTenant::route('/{record}/edit'),
=======
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
>>>>>>> origin/develop
        ];
    }
}
