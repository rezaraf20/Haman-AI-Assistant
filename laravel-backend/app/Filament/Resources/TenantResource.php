<?php
namespace App\Filament\Resources;

use App\Models\Tenant;
use App\Models\Plan;
use Filament\Forms\Form;
use Filament\Forms\Components\{TextInput, Select};
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, BadgeColumn};
use Filament\Tables\Filters\SelectFilter;
use App\Filament\Resources\TenantResource\Pages;

class TenantResource extends Resource {
    protected static ?string $model = Tenant::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?int $navigationSort = 1;

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
        ]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
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
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array {
        return [
            'index' => Pages\ListTenants::route('/'),
            'edit'  => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
