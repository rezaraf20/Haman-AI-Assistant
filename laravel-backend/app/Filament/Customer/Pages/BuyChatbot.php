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

class BuyChatbot extends Page implements HasForms {
    use InteractsWithForms;

    protected static string $view = 'filament.customer.pages.buy-chatbot';
    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';
    protected static ?string $navigationLabel = 'خرید چت‌بات جدید';
    protected static ?string $title = 'خرید چت‌بات جدید';

    public ?array $data = [];
    public ?string $newApiKey = null;
    public ?string $newChatbotName = null;

    public function mount(): void {
        $this->form->fill();
    }

    public function form(Form $form): Form {
        return $form->schema([
            Select::make('type')
                ->label('نوع چت‌بات')
                ->options(fn () => ChatbotTypePrice::active()->get()->mapWithKeys(
                    fn ($p) => [$p->type => $p->name . ' — ' . number_format($p->price_toman) . ' تومان']
                ))
                ->required()
                ->live(),
            TextInput::make('name')->label('نام چت‌بات')->required()->maxLength(255),
            TextInput::make('primary_domain')
                ->label('دامنه سایت')
                ->required()
                ->maxLength(255)
                ->dehydrateStateUsing(fn ($state) => DomainNormalizer::normalize($state))
                ->helperText('این دامنه بعداً فقط توسط پشتیبانی قابل تغییره — لطفاً درست وارد کنید. مثال: shop.com'),
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
            Notification::make()->title('این نوع چت‌بات در حال حاضر قابل خرید نیست')->danger()->send();
            return;
        }

        $tenant = Tenant::where('id', auth()->user()->tenant_id)->lockForUpdate()->first();
        $price  = $priceRow->price_toman;

        if ($tenant->wallet_balance_toman < $price) {
            Notification::make()
                ->title('موجودی کیف پول کافی نیست')
                ->body('لطفاً ابتدا کیف پول خود را شارژ کنید.')
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
            ['description' => "خرید چت‌بات جدید: {$state['name']} ({$priceRow->name})"],
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
            name: "{$state['name']} — WordPress plugin",
            createdBy: auth()->id(),
        );

        $this->newApiKey      = $plaintext;
        $this->newChatbotName = $state['name'];
        $this->form->fill();

        Notification::make()->title('چت‌بات با موفقیت خریداری شد')->success()->send();
    }
}
