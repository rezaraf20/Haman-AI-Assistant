<?php
namespace App\Filament\Customer\Pages;

use App\Models\ChatbotTypePrice;
use App\Models\Tenant;
use App\Models\ApiKey;
use App\Models\Tenant\Chatbot;
use App\Services\WalletService;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{TextInput, Select};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\DomainNormalizer;
use App\Support\Money;

class BuyChatbot extends Page implements HasForms {
    use InteractsWithForms;

    protected static string $view = 'filament.customer.pages.buy-chatbot';
    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    public static function getNavigationLabel(): string { return __('chatbot.buy_new_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('chatbot.buy_new_nav'); }

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
                    fn ($p) => [$p->type => $p->name . ' — ' . Money::toman($p->price_toman)]
                ))
                ->required()
                ->live(),
            TextInput::make('name')->label(__('chatbot.name_field'))->required()->maxLength(255),
            TextInput::make('primary_domain')
                ->label(__('chatbot.site_domain'))
                ->required()
                ->maxLength(255)
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

    public function purchase(): void {
        $state = $this->form->getState();
        $priceRow = ChatbotTypePrice::where('type', $state['type'])->active()->first();

        if (!$priceRow) {
            Notification::make()->title(__('chatbot.type_unavailable'))->danger()->send();
            return;
        }

        $tenant = Tenant::where('id', auth()->user()->tenant_id)->lockForUpdate()->first();
        $price  = $priceRow->price_toman;

        if ($tenant->wallet_balance_toman < $price) {
            Notification::make()
                ->title(__('wallet.insufficient_balance'))
                ->body(__('wallet.topup_first'))
                ->danger()
                ->send();
            return;
        }

        // Debit first — if chatbot creation below throws, the wallet transaction
        // ledger still accurately reflects what was charged; a failed chatbot
        // create is a bug to fix, not something that should silently also lose
        // the payment record.
        app(WalletService::class)->applyCompletedTransaction(
            $tenant, 'plan_charge', -$price,
            ['description' => __('chatbot.purchase_description', ['name' => $state['name'], 'type' => $priceRow->name])],
        );

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $chatbot = Chatbot::create([
            'id'                  => (string) Str::uuid(),
            'name'                => $state['name'],
            'type'                => $state['type'],
            'status'              => 'active',
            'is_active'           => true,
            'embedding_model'     => 'models/text-embedding-004',
            'llm_model'           => 'gemini-1.5-flash',
            'temperature'         => 0.3,
            'retrieval_top_k'     => 8,
            'retrieval_threshold' => 0.60,
            'memory_window'       => 6,
            'language'            => 'fa',
            'response_language'   => 'fa',
        ]);
        DB::statement("SET search_path TO public");

        DB::table('chatbot_index')->insert([
            'chatbot_id'           => $chatbot->id,
            'tenant_id'            => $tenant->id,
            'schema_name'          => $tenant->schema_name,
            'is_active'            => true,
            'name'                 => $state['name'],
            'primary_domain'       => $state['primary_domain'],
            'expires_at'           => now()->addMonth(),
            'monthly_price_toman'  => $price,
        ]);

        [, $plaintext] = ApiKey::generate(
            tenantId: $tenant->id,
            chatbotId: $chatbot->id,
            name: __('chatbot.wp_plugin_key_name', ['name' => $state['name']]),
            createdBy: auth()->id(),
        );

        $this->newApiKey      = $plaintext;
        $this->newChatbotName = $state['name'];
        $this->form->fill();

        Notification::make()->title(__('chatbot.purchased_success'))->success()->send();
    }
}
