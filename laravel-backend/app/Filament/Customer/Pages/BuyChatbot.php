<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotTypePrice;
use App\Models\Tenant;
use App\Exceptions\InsufficientBalance;
use App\Services\PurchaseService;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{TextInput, Select};
use Filament\Notifications\Notification;
use App\Support\DomainNormalizer;
use App\Support\Money;

class BuyChatbot extends Page implements HasForms {
    use InteractsWithForms;

    protected static string $view = 'filament.customer.pages.buy-chatbot';
    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    public static function getNavigationLabel(): string { return __('chatbot.buy_new_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('chatbot.buy_new_nav'); }
    public function getSubheading(): ?string { return __('portal.page_buy_chatbot'); }

    public ?array $data = [];
    public ?string $newApiKey = null;
    public ?string $newChatbotName = null;

    public function mount(): void {
        $this->form->fill();
    }

    public function form(Form $form): Form {
        return $form->schema([
            Select::make('type')
                ->label(__('chatbot.type_select'))
                ->options(fn () => ChatbotTypePrice::active()->get()->mapWithKeys(
                    fn ($p) => [$p->type => $p->display_name . ' — ' . Money::forCurrentTenant($p->price_toman)]
                ))
                ->required()
                ->live(),
            TextInput::make('name')->label(__('chatbot.name_field'))->required()->maxLength(255),
            TextInput::make('primary_domain')
                ->label(__('chatbot.site_domain'))
                ->required()
                ->maxLength(255)
                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                    if (!DomainNormalizer::isValid(DomainNormalizer::normalize((string) $value))) $fail(__('chatbot_domain.invalid'));
                })
                ->dehydrateStateUsing(fn ($state) => DomainNormalizer::normalize($state))
                ->helperText(__('chatbot.domain_locked_help')),
        ])->statePath('data');
    }

    public function getSelectedPrice(): int {
        $type = $this->data['type'] ?? null;
        if (!$type) return 0;
        return ChatbotTypePrice::where('type', $type)->value('price_toman') ?? 0;
    }

    public function getWalletBalance(): int {
        return (int) (auth()->user()->tenant->wallet_balance_toman ?? 0);
    }

    /** Formatted in the tenant's own currency — getWalletBalance()/getSelectedPrice() stay raw Toman for the actual purchase-affordability comparison in purchase() below. */
    public function getWalletBalanceDisplay(): string {
        return Money::forCurrentTenant($this->getWalletBalance());
    }

    public function getSelectedPriceDisplay(): string {
        return Money::forCurrentTenant($this->getSelectedPrice());
    }

    /** Debit, chatbot, index row and plugin key are one transaction — see PurchaseService::buyChatbot(). */
    public function purchase(): void {
        $state = $this->form->getState();

        try {
            [, $plaintext] = app(PurchaseService::class)->buyChatbot(
                Tenant::findOrFail(auth()->user()->tenant_id),
                (string) $state['type'], (string) $state['name'], (string) $state['primary_domain'], auth()->id(),
            );
        } catch (InsufficientBalance) {
            Notification::make()->title(__('wallet.insufficient_balance'))->body(__('wallet.topup_first'))->danger()->send();
            return;
        } catch (\DomainException $e) {
            match ($e->getMessage()) {
                'chatbot_limit' => Notification::make()
                    ->title(__('plan.chatbot_limit_reached'))
                    ->body(__('plan.chatbot_limit_reached_body', ['limit' => Tenant::find(auth()->user()->tenant_id)?->plan?->max_chatbots]))
                    ->danger()->send(),
                'type_unavailable' => Notification::make()->title(__('chatbot.type_unavailable'))->danger()->send(),
                default => Notification::make()->title(__('billing.unavailable'))->danger()->send(),
            };
            return;
        }

        $this->newApiKey      = $plaintext;
        $this->newChatbotName = $state['name'];
        $this->form->fill();

        Notification::make()->title(__('chatbot.purchased_success'))->success()->send();
    }
}
