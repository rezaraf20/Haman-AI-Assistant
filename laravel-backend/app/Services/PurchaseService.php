<?php
namespace App\Services;

use App\Exceptions\InsufficientBalance;
use App\Models\{ApiKey, ChatbotIndexEntry, ChatbotTypePrice, Invoice, Plan, Tenant, TokenPackage};
use App\Models\Tenant\Chatbot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buying things from the wallet: token packages, chatbots, chatbot renewals.
 * (Plan changes and invoices are BillingService.)
 *
 * These used to live inside the Filament pages, each with its own copy of
 * "lock the tenant, check the balance, debit, then grant" — the lock taken
 * outside any transaction, the balance never re-checked by the debit, and
 * the grant done after the debit had already committed. Here every purchase
 * is WalletService::debit() with the grant inside it, and every price is
 * read from the database, never from the page.
 *
 * Throws InsufficientBalance, or DomainException with a reason code the
 * page turns into a message.
 */
class PurchaseService
{
    public function __construct(
        private WalletService $wallet,
        private BillingService $billing,
        private TenantService $tenants,
        private QuotaService $quota,
    ) {}

    /** @throws InsufficientBalance|\DomainException */
    public function buyTokens(Tenant $tenant, TokenPackage $package): void
    {
        $package = TokenPackage::query()->active()->whereKey($package->getKey())->first()
            ?? throw new \DomainException('package_unavailable');

        $this->wallet->debit($tenant, (int) $package->price_toman, 'plan_charge', [
            'description' => __('plan.token_purchase_description', ['name' => $package->name, 'amount' => $package->token_amount]),
            'metadata' => ['token_package_id' => $package->getKey()],
        ], function (Tenant $locked) use ($package) {
            // The free tier upgrades rather than topping up around its
            // limit — checked against the plan under the lock.
            if (!(Plan::find($locked->plan_id)?->can_purchase_tokens ?? true)) {
                throw new \DomainException('upgrade_required');
            }
            Tenant::where('id', $locked->id)->increment('bonus_tokens', (int) $package->token_amount);
        });
    }

    /**
     * One more month of a paid chatbot. If its renewal invoice is already
     * open, this pays that invoice — same period, same result, and the
     * automatic charge then has nothing left to do.
     *
     * @throws InsufficientBalance|\DomainException
     */
    public function renewChatbot(Tenant $tenant, ChatbotIndexEntry $entry): void
    {
        if ((string) $entry->tenant_id !== (string) $tenant->id) {
            throw new \DomainException('not_yours');
        }
        $price = (int) $entry->monthly_price_toman;
        if ($price <= 0) {
            throw new \DomainException('not_renewable');
        }

        $open = Invoice::where('purpose', Invoice::CHATBOT)->where('subject_id', (string) $entry->chatbot_id)
            ->where('status', 'open')->orderBy('period_start')->first();
        if ($open) {
            $this->billing->payInvoice($open);
            return;
        }

        $this->wallet->debit($tenant, $price, 'plan_charge', [
            'description' => __('chatbot.renewal_description', ['name' => $entry->name]),
            'metadata' => ['chatbot_id' => (string) $entry->chatbot_id],
        ], function () use ($entry) {
            $locked = ChatbotIndexEntry::where('chatbot_id', $entry->chatbot_id)->lockForUpdate()->firstOrFail();
            $base = ($locked->expires_at && $locked->expires_at->isFuture()) ? $locked->expires_at : now();
            ChatbotIndexEntry::where('chatbot_id', $locked->chatbot_id)->update(['expires_at' => $base->copy()->addMonth()]);

            // Back on only if it was off for having expired; see
            // BillingService::extendChatbot().
            if (!$locked->is_active && $locked->disabled_reason === 'expired') {
                $this->tenants->setChatbotActive($locked->chatbot_id, true);
            }
        });
    }

    /**
     * A new paid chatbot: debit, create it, register it, issue its plugin
     * key — all or nothing.
     *
     * @return array{0: Chatbot, 1: string} the chatbot and its plaintext API key (shown once)
     * @throws InsufficientBalance|\DomainException
     */
    public function buyChatbot(Tenant $tenant, string $type, string $name, string $domain, ?string $createdBy): array
    {
        $priceRow = ChatbotTypePrice::where('type', $type)->active()->first()
            ?? throw new \DomainException('type_unavailable');
        $price = (int) $priceRow->price_toman;

        $result = null;
        $grant = function (Tenant $locked) use ($priceRow, $price, $name, $domain, $createdBy, &$result) {
            $schema = $locked->schema_name;
            try {
                DB::statement("SET search_path TO {$schema}, public");
                // Counted under the tenant lock, so two purchases cannot
                // both see room for one more chatbot.
                $locked->loadMissing('plan');
                if (!$this->quota->canCreateChatbot($locked, Chatbot::count())) {
                    throw new \DomainException('chatbot_limit');
                }
                $chatbot = Chatbot::create([
                    'id'                  => (string) Str::uuid(),
                    'name'                => $name,
                    'type'                => $priceRow->type,
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
            } finally {
                DB::statement('SET search_path TO public');
            }

            DB::table('chatbot_index')->insert([
                'chatbot_id'          => $chatbot->id,
                'tenant_id'           => $locked->id,
                'schema_name'         => $schema,
                'is_active'           => true,
                'name'                => $name,
                'primary_domain'      => $domain,
                'expires_at'          => now()->addMonth(),
                'monthly_price_toman' => $price,
            ]);

            [, $plaintext] = ApiKey::generate(
                tenantId: $locked->id,
                chatbotId: $chatbot->id,
                name: __('chatbot.wp_plugin_key_name', ['name' => $name]),
                createdBy: $createdBy,
            );
            $result = [$chatbot, $plaintext];
        };

        $attrs = [
            'description' => __('chatbot.purchase_description', ['name' => $name, 'type' => $priceRow->name]),
            'metadata' => ['chatbot_type' => $priceRow->type],
        ];
        if ($price > 0) {
            $this->wallet->debit($tenant, $price, 'plan_charge', $attrs, $grant);
        } else {
            DB::transaction(fn () => $grant(Tenant::where('id', $tenant->id)->lockForUpdate()->firstOrFail()));
        }

        return $result;
    }
}
