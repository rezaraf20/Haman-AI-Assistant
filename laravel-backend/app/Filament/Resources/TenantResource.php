<?php
namespace App\Filament\Resources;

use App\Models\Tenant;
use App\Models\Plan;
use App\Models\User;
use App\Models\ApiKey;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, Section, Textarea};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, BadgeColumn};
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use App\Support\Jalali;
use App\Support\Money;
use App\Filament\Resources\TenantResource\Pages;

class TenantResource extends Resource {
    protected static ?string $model = Tenant::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?int $navigationSort = 1;

    // Static properties can't call __() (property defaults must be
    // compile-time constants) — overriding these getters instead is
    // Filament's documented pattern for a locale-dependent label, since
    // they're called fresh on every request instead of once at class load.
    public static function getNavigationLabel(): string { return __('panel.tenants_nav'); }
    public static function getModelLabel(): string { return __('panel.tenant_singular'); }
    public static function getPluralModelLabel(): string { return __('panel.tenants_nav'); }

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
            TextInput::make('name')->label(__('common.name'))->required()->maxLength(255),
            TextInput::make('email')->label(__('common.email'))->email()->required()->maxLength(255)
                ->unique(table: 'tenants', column: 'email', ignoreRecord: true),
            TextInput::make('password')
                ->label(__('common.password'))
                ->password()->revealable()->required()->minLength(8)
                ->visibleOn('create')
                ->helperText(__('panel.tenant_password_help')),
            TextInput::make('phone')->label(__('common.phone'))->maxLength(50),
            Select::make('plan_id')->label(__('panel.plan'))->relationship('plan', 'name')->searchable(),
            Select::make('status')->label(__('common.status'))->options([
                'trial' => __('common.status_trial'),
                'active' => __('common.status_active'),
                'suspended' => __('common.status_suspended'),
                'cancelled' => __('common.status_cancelled'),
            ])->required(),
            // Read-only — these live on the owning User row (phone-based
            // signup profile), not on Tenant itself, so they're populated via
            // afterStateHydrated() rather than a normal bound field. disabled()
            // fields aren't submitted unless explicitly dehydrated(), so this
            // is display-only and can't accidentally overwrite user data.
            Section::make(__('panel.owner_info_section'))
                ->schema([
                    TextInput::make('owner_national_id')->label(__('panel.national_id'))->disabled()
                        ->afterStateHydrated(fn ($component, $record) => $component->state($record?->owner?->national_id)),
                    Textarea::make('owner_address')->label(__('common.address'))->disabled()->rows(2)
                        ->afterStateHydrated(fn ($component, $record) => $component->state($record?->owner?->address)),
                ])
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('common.name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('common.email'))->searchable(),
                TextColumn::make('phone')->label(__('common.phone'))->searchable()->placeholder('—'),
                TextColumn::make('owner.national_id')->label(__('panel.national_id'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('owner.address')->label(__('common.address'))->limit(30)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plan.name')->label(__('panel.plan'))->badge(),
                BadgeColumn::make('status')->label(__('common.status'))->colors([
                    'success' => 'active',
                    'warning' => 'trial',
                    'danger'  => ['suspended', 'cancelled'],
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'trial' => __('common.status_trial'), 'active' => __('common.status_active'),
                    'suspended' => __('common.status_suspended'), 'cancelled' => __('common.status_cancelled'),
                    default => $state,
                }),
                TextColumn::make('wallet_balance_toman')->label(__('common.wallet'))
                    ->formatStateUsing(fn (int $state) => Money::toman($state))
                    ->sortable(),
                TextColumn::make('usage_tokens_current')->label(__('panel.token_usage'))
                    ->formatStateUsing(fn ($record) => number_format($record->usage_tokens_current) . ' / ' . ($record->plan?->max_tokens_monthly ? number_format($record->plan->max_tokens_monthly) : '∞'))
                    ->color(fn ($record) => $record->isTokenQuotaExceeded() ? 'danger' : null),
                TextColumn::make('trial_ends_at')->label(__('panel.trial_ends_at'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
                TextColumn::make('created_at')->label(__('common.created_at'))->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('common.status'))->options([
                    'trial' => __('common.status_trial'), 'active' => __('common.status_active'),
                    'suspended' => __('common.status_suspended'), 'cancelled' => __('common.status_cancelled'),
                ]),
                SelectFilter::make('plan_id')->relationship('plan', 'name')->label(__('panel.plan')),
            ])
            ->actions([
                Action::make('delete')
                    ->label(__('common.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Tenant $record) => __('panel.delete_tenant_heading', ['name' => $record->name]))
                    ->modalDescription(__('panel.delete_tenant_description'))
                    ->modalSubmitActionLabel(__('panel.delete_tenant_confirm'))
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

                        Notification::make()->title(__('panel.delete_tenant_success', ['name' => $name]))->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
