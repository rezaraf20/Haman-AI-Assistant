<?php
namespace App\Filament\Resources;

use App\Models\Ticket;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\{TextColumn, BadgeColumn};
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\Action;
use App\Support\Jalali;
use App\Filament\Resources\TicketResource\Pages;

class TicketResource extends Resource {
    protected static ?string $model = Ticket::class;
    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';
    protected static ?int $navigationSort = 8;
    protected static ?string $navigationLabel = 'تیکت‌های پشتیبانی';
    protected static ?string $modelLabel = 'تیکت';
    protected static ?string $pluralModelLabel = 'تیکت‌ها';

    public static function canCreate(): bool { return false; } // admin replies to tickets, doesn't open them
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return true; }

    // 'open' already means "customer created or replied and it hasn't been
    // handled yet" (see ManageTicket::submitReply() and the customer portal's
    // Tickets::table(), which both flip status back to open on customer
    // activity) — reusing that status instead of a separate seen/unseen flag.
    public static function getNavigationBadge(): ?string {
        $count = Ticket::where('status', 'open')->count();
        return $count > 0 ? (string) $count : null;
    }
    public static function getNavigationBadgeColor(): ?string { return 'danger'; }

    public static function form(Form $form): Form {
        return $form->schema([]);
    }

    public static function table(Table $table): Table {
        return $table
            ->columns([
                TextColumn::make('subject')->label('موضوع')->searchable(),
                TextColumn::make('tenant.name')->label('مشتری')->searchable(),
                BadgeColumn::make('priority')->label('اولویت')->colors([
                    'gray' => 'low', 'warning' => 'normal', 'danger' => 'high',
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'low' => 'کم', 'normal' => 'عادی', 'high' => 'زیاد', default => $state,
                }),
                BadgeColumn::make('status')->label('وضعیت')->colors([
                    'warning' => 'open', 'success' => 'answered', 'gray' => 'closed',
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'open' => 'باز', 'answered' => 'پاسخ داده‌شده', 'closed' => 'بسته', default => $state,
                }),
                TextColumn::make('created_at')->label('تاریخ ثبت')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
                TextColumn::make('updated_at')->label('آخرین فعالیت')->formatStateUsing(fn ($state) => Jalali::dateTime($state))->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('وضعیت')->options(['open' => 'باز', 'answered' => 'پاسخ داده‌شده', 'closed' => 'بسته']),
                SelectFilter::make('priority')->label('اولویت')->options(['low' => 'کم', 'normal' => 'عادی', 'high' => 'زیاد']),
            ])
            ->actions([
                Action::make('open')
                    ->label('باز کردن')
                    ->url(fn (Ticket $record) => static::getUrl('manage', ['record' => $record])),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListTickets::route('/'),
            'manage' => Pages\ManageTicket::route('/{record}/manage'),
        ];
    }
}
