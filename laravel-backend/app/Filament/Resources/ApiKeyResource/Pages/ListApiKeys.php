<?php
namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Actions\Action as FieldAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListApiKeys extends ListRecords {
    protected static string $resource = ApiKeyResource::class;

    public ?string $newPlaintextKey = null;

    // CreateApiKey stashes the plaintext key in the session (see there for why
    // put() rather than flash()) and this page pulls + shows it exactly once,
    // in a modal the admin must explicitly dismiss — a toast notification is
    // too easy to miss/lose before it's copied, and the key can never be
    // retrieved again after this.
    public function mount(): void {
        parent::mount();
        $this->newPlaintextKey = session()->pull('new_api_key_plaintext');
        if (filled($this->newPlaintextKey)) {
            $this->mountAction('showNewKey');
        }
    }

    // Both actions must live in this one method: Filament's Page base class
    // only ever calls getHeaderActions() to populate its mountable-action
    // registry (getActions() is a deprecated alias getHeaderActions() used to
    // fall back to — once you override getHeaderActions() yourself, that
    // fallback path is gone, and anything defined only in a separate
    // getActions() override is never registered or mountable at all, which is
    // why mountAction('showNewKey') was silently doing nothing).
    protected function getHeaderActions(): array {
        return [
            CreateAction::make(),
            Action::make('showNewKey')
                ->hidden()
                ->modalHeading(__('panel.new_key_created_heading'))
                // Text used to say this was the only time the key would ever
                // be shown — no longer true since ApiKeyResource's 'showKey'
                // action added persistent reveal; corrected while translating.
                ->modalDescription(__('panel.new_key_created_description'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('panel.close_copied'))
                ->closeModalByClickingAway(false)
                ->form([
                    // TextInput::copyable() doesn't exist in this Filament version
                    // (that's an Infolists\Components\TextEntry method) — built the
                    // copy button by hand instead, via a suffix action with a plain
                    // Alpine click handler that reads the field by a fixed DOM id
                    // rather than relying on Filament's internal Alpine scoping.
                    TextInput::make('key')
                        ->label(__('panel.api_key'))
                        ->default(fn () => $this->newPlaintextKey)
                        ->readOnly()
                        ->extraInputAttributes(['class' => 'font-mono text-sm', 'id' => 'hamman-new-api-key'])
                        ->suffixActions([
                            FieldAction::make('copy')
                                ->icon('heroicon-m-clipboard')
                                ->label(__('common.copy'))
                                ->alpineClickHandler(
                                    "navigator.clipboard.writeText(document.getElementById('hamman-new-api-key').value)"
                                ),
                        ]),
                ]),
        ];
    }
}
