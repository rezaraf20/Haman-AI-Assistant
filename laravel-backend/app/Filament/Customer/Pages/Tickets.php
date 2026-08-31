<?php
namespace App\Filament\Customer\Pages;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\{TextColumn, BadgeColumn};
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\{TextInput, Select, Textarea, Placeholder};
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use App\Support\Jalali;

class Tickets extends Page implements HasTable {
    use InteractsWithTable;

    protected static string $view = 'filament.customer.pages.tickets';
    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';
    protected static ?string $navigationLabel = 'پشتیبانی';
    protected static ?string $title = 'تیکت‌های پشتیبانی';

    // Mirrors TicketResource::getNavigationBadge() on the admin side: 'answered'
    // means the admin replied and it's now waiting on the customer, the same
    // way 'open' means a customer replied and it's waiting on the admin.
    public static function getNavigationBadge(): ?string {
        $tenantId = auth()->user()?->tenant_id;
        if (!$tenantId) return null;
        $count = Ticket::where('tenant_id', $tenantId)->where('status', 'answered')->count();
        return $count > 0 ? (string) $count : null;
    }
    public static function getNavigationBadgeColor(): ?string { return 'danger'; }

    public function table(Table $table): Table {
        return $table
            ->query(fn () => Ticket::query()->where('tenant_id', auth()->user()->tenant_id))
            ->columns([
                TextColumn::make('subject')->label('موضوع'),
                BadgeColumn::make('priority')->label('اولویت')->colors([
                    'gray' => 'low', 'warning' => 'normal', 'danger' => 'high',
                ]),
                BadgeColumn::make('status')->label('وضعیت')->colors([
                    'warning' => 'open', 'success' => 'answered', 'gray' => 'closed',
                ]),
                TextColumn::make('created_at')->label('تاریخ ثبت')->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
                TextColumn::make('updated_at')->label('آخرین فعالیت')->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
            ])
            ->actions([
                Action::make('view')
                    ->label('مشاهده و پاسخ')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->modalHeading(fn (Ticket $record) => $record->subject)
                    ->modalSubmitActionLabel('ارسال پاسخ')
                    ->form(fn (Ticket $record) => [
                        Placeholder::make('thread')
                            ->label('')
                            ->content(fn () => new HtmlString($this->renderThread($record))),
                        Textarea::make('reply')->label('پاسخ شما')->rows(3),
                    ])
                    ->action(function (Ticket $record, array $data) {
                        if (filled($data['reply'] ?? null)) {
                            TicketMessage::create([
                                'ticket_id'   => $record->id,
                                'sender_type' => 'customer',
                                'sender_id'   => auth()->id(),
                                'body'        => $data['reply'],
                            ]);
                            // Customer replying to an answered/closed ticket reopens it for the admin.
                            $record->update(['status' => 'open']);
                        }
                        Notification::make()->title('پاسخ شما ثبت شد')->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('new')
                    ->label('تیکت جدید')
                    ->icon('heroicon-o-plus')
                    ->form([
                        TextInput::make('subject')->label('موضوع')->required()->maxLength(255),
                        Select::make('priority')->label('اولویت')->options([
                            'low' => 'کم', 'normal' => 'عادی', 'high' => 'زیاد',
                        ])->default('normal')->required(),
                        Textarea::make('message')->label('توضیحات')->required()->rows(4),
                    ])
                    ->action(function (array $data) {
                        $ticket = Ticket::create([
                            'tenant_id' => auth()->user()->tenant_id,
                            'subject'   => $data['subject'],
                            'priority'  => $data['priority'],
                            'status'    => 'open',
                        ]);
                        TicketMessage::create([
                            'ticket_id'   => $ticket->id,
                            'sender_type' => 'customer',
                            'sender_id'   => auth()->id(),
                            'body'        => $data['message'],
                        ]);
                        Notification::make()->title('تیکت شما ثبت شد')->success()->send();
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    private function renderThread(Ticket $ticket): string {
        $html = '<div class="space-y-3 max-h-80 overflow-y-auto">';
        foreach ($ticket->messages as $message) {
            $who = $message->sender_type === 'admin' ? 'پشتیبانی' : 'شما';
            $bg  = $message->sender_type === 'admin' ? 'background:#eef2ff' : 'background:#f9fafb';
            $html .= '<div style="padding:10px;border-radius:8px;' . $bg . '">'
                . '<div style="font-size:11px;color:#6b7280;margin-bottom:4px">' . e($who) . ' — ' . Jalali::dateTime($message->created_at) . '</div>'
                . '<div style="white-space:pre-wrap">' . e($message->body) . '</div>'
                . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
