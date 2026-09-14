<?php
namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Models\TicketMessage;
use App\Support\PlatformAccess;
use App\Support\PlatformActivity;
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

    // Stated explicitly rather than inherited. The resource's canEdit() is
    // false by design — staff reply to tickets, they do not rewrite them —
    // so leaning on the resource's gates would tie this page's access to a
    // rule about something else. Filament checks this on the direct route.
    public static function canAccess(array $parameters = []): bool {
        return PlatformAccess::allows('tickets');
    }

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
        PlatformAccess::authorize('tickets');
        $state = $this->form->getState();
        $statusBefore = $this->record->status;
        $tenantId = $this->record->tenant_id ? (string) $this->record->tenant_id : null;

        if (filled($state['reply'] ?? null)) {
            $message = TicketMessage::create([
                'ticket_id'   => $this->record->id,
                'sender_type' => 'admin',
                'sender_id'   => auth()->id(),
                'body'        => $state['reply'],
            ]);

            // The reply body is not copied into the log. It is already
            // stored on the ticket, and duplicating customer-written text
            // into a table that outlives the ticket serves nothing. What
            // is recorded is who replied, to which ticket, and when.
            PlatformActivity::record(
                'ticket_replied',
                tenantId: $tenantId,
                subjectType: 'ticket',
                subjectId: (string) $this->record->id,
                after: ['message_id' => (string) $message->id, 'length' => mb_strlen($state['reply'])],
            );
        }

        $this->record->update(['status' => $state['status']]);
        $this->record->touch();

        if ($statusBefore !== $state['status']) {
            PlatformActivity::record(
                'ticket_status_changed',
                tenantId: $tenantId,
                subjectType: 'ticket',
                subjectId: (string) $this->record->id,
                before: ['status' => $statusBefore],
                after: ['status' => $state['status']],
            );
        }

        $this->form->fill(['status' => $state['status'], 'reply' => '']);

        Notification::make()->title(__('common.saved'))->success()->send();
    }
}
