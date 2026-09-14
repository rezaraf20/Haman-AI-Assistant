<?php
namespace App\Filament\Resources;

use App\Support\PlatformAccess;

use App\Models\Tenant;
use App\Models\Plan;
use App\Models\User;
use App\Models\ApiKey;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select, Section, Textarea};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, BadgeColumn, IconColumn};
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{DB, Cache};
use App\Support\PlatformActivity;
use App\Support\Jalali;
use App\Support\Money;
use App\Filament\Pages\TenantConversations;
use App\Filament\Resources\TenantResource\Pages;

class TenantResource extends Resource {
    // Support sees the customer list and status; creating, editing or
    // deleting a tenant (and with it plan changes) stays with admins.
    // Enforced by Filament on the direct route as well, not just in the
    // navigation — see PlatformAccess.
    public static function canViewAny(): bool { return PlatformAccess::allows('tenants_read'); }
    public static function canView($record): bool { return PlatformAccess::allows('tenants_read'); }
    public static function canCreate(): bool { return PlatformAccess::allows('tenant_lifecycle'); }
    public static function canEdit($record): bool { return PlatformAccess::allows('tenant_lifecycle'); }
    public static function canDelete($record): bool { return PlatformAccess::allows('tenant_lifecycle'); }
    public static function canDeleteAny(): bool { return PlatformAccess::allows('tenant_lifecycle'); }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('tenants_read'); }

    /**
     * The plugin-status columns are aggregated here rather than queried per
     * row, so the list costs the same one query it did before. Revoked keys
     * are not counted: an inactive key means the shop is not connected.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['apiKeys' => fn ($q) => $q->where('is_active', true)])
            ->withMax('apiKeys', 'last_used_at');
    }

    protected static ?string $model = Tenant::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?int $navigationSort = 1;

    // Static properties can't call __() (property defaults must be
    // compile-time constants) — overriding these getters instead is
    // Filament's documented pattern for a locale-dependent label, since
    // they're called fresh on every request instead of once at class load.
    public static function getNavigationLabel(): string { return __('panel.tenants_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customers'); }
    public static function getModelLabel(): string { return __('panel.tenant_singular'); }
    public static function getPluralModelLabel(): string { return __('panel.tenants_nav'); }


    // Cleared in ListTenants::mount() the first time the admin opens this
    // list — see that file for why a bulk "mark all visible as seen" fits
    // this resource better than per-record tracking (unlike tickets, there's
    // no natural single-record "open" action a customer row funnels through).
    public static function getNavigationBadge(): ?string {
        // Filament renders the badge more than once per request (desktop +
        // mobile nav) — cache briefly so that doesn't mean two live COUNT
        // queries every time, on top of avoiding a query on every request.
        $count = \Illuminate\Support\Facades\Cache::remember('nav-badge:tenants-unseen', 60, function () {
            return Tenant::whereNull('admin_seen_at')->count();
        });
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
                // Whether the shop's plugin has a key, and when it last used
                // it. Support needs this to answer "is it even installed?"
                // without ever being shown the key itself — the VALUE is
                // admin-only (PlatformAccess::ADMIN_ONLY 'api_key_values').
                IconColumn::make('has_api_key')
                    ->label(__('panel.plugin_key_issued'))
                    ->boolean()
                    ->getStateUsing(fn (Tenant $record) => (bool) $record->api_keys_count),
                TextColumn::make('api_keys_max_last_used_at')
                    ->label(__('panel.plugin_last_contact'))
                    ->placeholder(__('panel.plugin_never_contacted'))
                    ->formatStateUsing(fn ($state) => $state ? Jalali::dateTime($state) : null)
                    ->color(fn ($state) => $state ? null : 'danger'),
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
                // The reader itself demands a reason and masks contacts;
                // this is only the way in. Hidden from anyone without the
                // capability, and TenantConversations::canAccess() refuses
                // the URL independently.
                Action::make('conversations')
                    ->label(__('staff_conversations.title'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('gray')
                    ->visible(fn () => PlatformAccess::allows('conversations'))
                    ->url(fn (Tenant $record) => TenantConversations::getUrl(['tenant' => $record->id])),
                // Two things support is genuinely asked to do, and which
                // need no sight of any secret value to do. Both are gated
                // on their own capability and both write to the activity
                // log — a rotation in particular is invisible afterwards
                // unless it was recorded when it happened.
                Action::make('clearCache')
                    ->label(__('panel.clear_cache'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn () => PlatformAccess::allows('sync_operate'))
                    ->requiresConfirmation()
                    ->modalDescription(__('panel.clear_cache_description'))
                    ->action(function (Tenant $record) {
                        PlatformAccess::authorize('sync_operate');
                        $cleared = static::clearTenantCaches($record);

                        PlatformActivity::record(
                            'cache_cleared',
                            tenantId: (string) $record->id,
                            subjectType: 'tenant',
                            subjectId: (string) $record->id,
                            after: ['keys_cleared' => $cleared],
                        );

                        Notification::make()->title(__('panel.clear_cache_done'))->success()->send();
                    }),

                Action::make('rotateWebhookSecret')
                    ->label(__('panel.rotate_webhook_secret'))
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->visible(fn () => PlatformAccess::allows('webhook_secret_rotate'))
                    ->requiresConfirmation()
                    ->modalDescription(__('panel.rotate_webhook_secret_description'))
                    ->action(function (Tenant $record) {
                        PlatformAccess::authorize('webhook_secret_rotate');

                        // Neither the old nor the new value is shown to the
                        // operator or written to the log. Only the fact that
                        // it changed, and when, which is all a reviewer
                        // needs and all support is entitled to.
                        $record->update([
                            'settings' => array_merge($record->settings ?? [], [
                                'webhook_secret' => \Illuminate\Support\Str::random(32),
                            ]),
                        ]);

                        PlatformActivity::record(
                            'webhook_secret_rotated',
                            tenantId: (string) $record->id,
                            subjectType: 'tenant',
                            subjectId: (string) $record->id,
                            after: ['rotated' => true],
                        );

                        Notification::make()
                            ->title(__('panel.rotate_webhook_secret_done'))
                            ->body(__('panel.rotate_webhook_secret_done_body'))
                            ->success()->send();
                    }),

                Action::make('delete')
                    ->label(__('common.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    // A hand-written action, so canDelete() does not gate it.
                    // The closure below drops the customer's whole schema,
                    // which makes this the single most dangerous button in
                    // the panel — hence the guard inside it as well.
                    ->visible(fn () => PlatformAccess::allows('tenant_lifecycle'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Tenant $record) => __('panel.delete_tenant_heading', ['name' => $record->name]))
                    ->modalDescription(__('panel.delete_tenant_description'))
                    ->modalSubmitActionLabel(__('panel.delete_tenant_confirm'))
                    ->action(function (Tenant $record) {
                        PlatformAccess::authorize('tenant_lifecycle');
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

    /**
     * Drops the cached report and dashboard data derived from this tenant's
     * own rows. Deliberately narrow: it clears what a stale-numbers
     * complaint is actually about, and touches no other tenant.
     */
    public static function clearTenantCaches(Tenant $tenant): int
    {
        $schema = $tenant->schema_name;
        $today  = now()->toDateString();

        $keys = ["customer:dashboard:{$tenant->id}", "suggestions:badge:{$tenant->id}"];
        foreach (['30d', '3m', '6m', '1y'] as $range) {
            $keys[] = "trends:{$schema}:{$range}:{$today}";
        }

        $cleared = 0;
        foreach ($keys as $key) {
            if (Cache::forget($key)) $cleared++;
        }
        return $cleared;
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
