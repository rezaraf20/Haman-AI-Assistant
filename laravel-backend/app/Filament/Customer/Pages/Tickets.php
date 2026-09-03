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

    public static function getNavigationLabel(): string { return __('ticket.support_nav'); }
    public function getTitle(): string { return __('ticket.plural'); }

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
                TextColumn::make('subject')->label(__('ticket.subject')),
                BadgeColumn::make('priority')->label(__('ticket.priority'))->colors([
                    'gray' => 'low', 'warning' => 'normal', 'danger' => 'high',
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'low' => __('ticket.priority_low'), 'normal' => __('ticket.priority_normal'),
                    'high' => __('ticket.priority_high'), default => $state,
                }),
                BadgeColumn::make('status')->label(__('common.status'))->colors([
                    'warning' => 'open', 'success' => 'answered', 'gray' => 'closed',
                ])->formatStateUsing(fn (string $state) => match ($state) {
                    'open' => __('ticket.status_open'), 'answered' => __('ticket.status_answered'),
                    'closed' => __('ticket.status_closed'), default => $state,
                }),
                TextColumn::make('created_at')->label(__('common.created_at'))->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
                TextColumn::make('updated_at')->label(__('ticket.last_activity'))->formatStateUsing(fn ($state) => Jalali::dateTime($state)),
            ])
            ->actions([
                Action::make('view')
                    ->label(__('ticket.view_and_reply'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->modalHeading(fn (Ticket $record) => $record->subject)
                    ->modalSubmitActionLabel(__('ticket.send_reply'))
                    ->form(fn (Ticket $record) => [
                        Placeholder::make('thread')
                            ->label('')
                            ->content(fn () => new HtmlString($this->renderThread($record))),
                        Textarea::make('reply')->label(__('ticket.your_reply'))->rows(3),
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
                        Notification::make()->title(__('ticket.reply_submitted'))->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('new')
                    ->label(__('ticket.new_ticket'))
                    ->icon('heroicon-o-plus')
                    ->form([
                        TextInput::make('subject')->label(__('ticket.subject'))->required()->maxLength(255),
                        Select::make('priority')->label(__('ticket.priority'))->options([
                            'low' => __('ticket.priority_low'), 'normal' => __('ticket.priority_normal'), 'high' => __('ticket.priority_high'),
                        ])->default('normal')->required(),
                        Textarea::make('message')->label(__('common.description'))->required()->rows(4),
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
                        Notification::make()->title(__('ticket.created_success'))->success()->send();
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    private function renderThread(Ticket $ticket): string {
        $html = '<div class="space-y-3 max-h-80 overflow-y-auto">';
        foreach ($ticket->messages as $message) {
            $who = $message->sender_type === 'admin' ? __('ticket.support_label') : __('ticket.you_label');
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
