<?php
namespace App\Filament\Resources;

use App\Filament\Resources\PlatformUserResource\Pages;
use App\Models\User;
use App\Support\PlatformAccess;
use App\Support\PlatformAudit;
use Filament\Forms\Components\{DatePicker, Section, Select, TextInput, Toggle};
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform staff accounts — support agents and admins.
 *
 * Admin-only, and the ONLY place platform_role is ever written. That
 * column is absent from User::$fillable precisely so no form, API payload
 * or tenant-facing path can grant it; this resource sets it explicitly on
 * the model instead of through mass assignment.
 *
 * The list is scoped to platform staff. Customers' user accounts are not
 * shown here at all — support has no business browsing the platform's
 * user table, and an admin managing staff does not need customers mixed
 * into the same list.
 */
class PlatformUserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-identification';
    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string { return __('platform_users.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_infrastructure'); }
    public static function getModelLabel(): string { return __('platform_users.singular'); }
    public static function getPluralModelLabel(): string { return __('platform_users.nav'); }

    // Staff accounts are admin business. Support must not be able to see
    // the roster, let alone grant itself a role.
    public static function canViewAny(): bool { return PlatformAccess::allows('platform_users'); }
    public static function canView($record): bool { return PlatformAccess::allows('platform_users'); }
    public static function canCreate(): bool { return PlatformAccess::allows('platform_users'); }
    public static function canEdit($record): bool { return PlatformAccess::allows('platform_users'); }
    // Staff are deactivated, not deleted: the audit log references them,
    // and a deleted row would leave those entries pointing at nothing.
    public static function canDelete($record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('platform_users'); }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('platform_role');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('platform_users.section_identity'))->schema([
                TextInput::make('first_name')->label(__('platform_users.first_name'))->required()->maxLength(100),
                TextInput::make('last_name')->label(__('platform_users.last_name'))->required()->maxLength(100),
                TextInput::make('platform_display_id')
                    ->label(__('platform_users.display_id'))
                    ->helperText(__('platform_users.display_id_help'))
                    ->maxLength(50),
                TextInput::make('avatar_url')->label(__('platform_users.avatar'))->url()->maxLength(1000),
            ])->columns(2),

            Section::make(__('platform_users.section_contact'))->schema([
                TextInput::make('email')->label(__('common.email'))->email()->required()->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('phone')->label(__('panel.mobile_number'))->tel()->maxLength(30),
            ])->columns(2),

            Section::make(__('platform_users.section_role'))->schema([
                Select::make('platform_role')
                    ->label(__('platform_users.role'))
                    ->options([
                        'support' => __('platform_users.role_support'),
                        'admin'   => __('platform_users.role_admin'),
                    ])
                    ->required()
                    ->helperText(__('platform_users.role_help')),
                DatePicker::make('platform_started_at')->label(__('platform_users.started_at')),
                Toggle::make('platform_is_active')
                    ->label(__('platform_users.is_active'))
                    ->helperText(__('platform_users.is_active_help'))
                    ->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('platform_display_id')->label(__('platform_users.display_id')),
                Tables\Columns\TextColumn::make('name')->label(__('platform_users.name'))
                    ->getStateUsing(fn (User $r) => trim($r->first_name . ' ' . $r->last_name) ?: $r->name),
                Tables\Columns\TextColumn::make('email')->label(__('common.email')),
                Tables\Columns\BadgeColumn::make('platform_role')->label(__('platform_users.role'))
                    ->colors(['danger' => 'admin', 'info' => 'support'])
                    ->formatStateUsing(fn ($state) => $state === 'admin'
                        ? __('platform_users.role_admin') : __('platform_users.role_support')),
                Tables\Columns\IconColumn::make('platform_is_active')->label(__('platform_users.is_active'))->boolean(),
                Tables\Columns\TextColumn::make('platform_started_at')->label(__('platform_users.started_at'))->date(),
                Tables\Columns\TextColumn::make('last_login_at')->label(__('platform_users.last_login'))->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Deactivation takes effect on the staff member's very next
                // request: User::canAccessPanel() reads platform_is_active
                // every time, so a session cookie that is still technically
                // valid stops being enough the moment this flips.
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $r) => $r->platform_is_active
                        ? __('platform_users.deactivate') : __('platform_users.activate'))
                    ->color(fn (User $r) => $r->platform_is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->platform_is_active = !$record->platform_is_active;
                        $record->save();

                        // API tokens are not covered by the panel gate, so
                        // they are revoked explicitly.
                        if (!$record->platform_is_active) {
                            $record->tokens()->delete();
                        }

                        PlatformAudit::record(
                            $record->platform_is_active ? 'staff_activated' : 'staff_deactivated',
                            subjectType: 'user',
                            subjectId: (string) $record->id,
                        );
                    }),
            ])
            ->defaultSort('platform_started_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPlatformUsers::route('/'),
            'create' => Pages\CreatePlatformUser::route('/create'),
            'edit'   => Pages\EditPlatformUser::route('/{record}/edit'),
        ];
    }
}
