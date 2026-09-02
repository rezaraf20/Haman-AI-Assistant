<?php
namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Models\TicketMessage;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{Textarea, Select};
use Filament\Notifications\Notification;

class ManageTicket extends Page implements HasForms {
    use InteractsWithRecord, InteractsWithForms;

    protected static string $resource = TicketResource::class;
    protected static string $view = 'filament.resources.ticket-resource.pages.manage-ticket';

    public ?array $data = [];

    public function mount(int|string $record): void {
        $this->record = $this->resolveRecord($record);
        $this->form->fill(['status' => $this->record->status, 'reply' => '']);
    }

    public function form(Form $form): Form {
        return $form->schema([
            Select::make('status')
                ->label(__('common.status'))
                ->options([
                    'open' => __('ticket.status_open'),
                    'answered' => __('ticket.status_answered'),
                    'closed' => __('ticket.status_closed'),
                ])
                ->required(),
            Textarea::make('reply')->label(__('ticket.reply'))->rows(4),
        ])->statePath('data');
    }

    public function submitReply(): void {
        $state = $this->form->getState();

        if (filled($state['reply'] ?? null)) {
            TicketMessage::create([
                'ticket_id'   => $this->record->id,
                'sender_type' => 'admin',
                'sender_id'   => auth()->id(),
                'body'        => $state['reply'],
            ]);
        }

        $this->record->update(['status' => $state['status']]);
        $this->record->touch();
        $this->form->fill(['status' => $state['status'], 'reply' => '']);

        Notification::make()->title(__('common.saved'))->success()->send();
    }
}
