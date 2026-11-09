<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * A second wallet, in USDT, for every tenant — Iranian or not — paid into
 * with USDT on BNB Smart Chain (BEP20).
 *
 * Amounts are integer micro-USDT (1 USDT = 1,000,000): six decimals, the
 * precision prices and balances need, as a bigint. The token itself has 18
 * decimals on chain; the exact on-chain amount is kept on crypto_transfers
 * (numeric(78,0)) and only converted to micro-USDT when credited.
 *
 * Its own ledger table, not rows in wallet_transactions: every existing
 * report sums wallet_transactions.amount_toman, and a USDT row there would
 * be added to Toman. No exchange rate anywhere — USDT prices are their own
 * column on each priced thing, and a USDT purchase debits the USDT wallet.
 *
 * How a payment is recognised (see App\Services\Crypto): every top-up
 * request gets a unique exact amount (e.g. 20.004213) payable to the one
 * receiving address the platform owner set; the scanner reads that
 * address's USDT Transfer logs and credits a request once its block is
 * final. No private key is on the server.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->bigInteger('wallet_balance_usdt')->default(0)->after('wallet_balance_toman');
            // Which wallet renewals are charged from: IRT | USDT.
            $t->string('billing_currency', 8)->default('IRT')->after('plan_paid_until');
        });

        Schema::create('usdt_wallet_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->foreign('tenant_id')->references('id')->on('tenants');
            // deposit | plan_charge | admin_adjustment | refund
            $t->string('type', 30);
            $t->bigInteger('amount_micro');            // signed
            $t->bigInteger('balance_after_micro');
            $t->text('description')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('crypto_transfer_id')->nullable()->unique();   // one credit per on-chain transfer, ever
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('crypto_transfers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->integer('chain_id');
            $t->string('token_contract', 42);
            $t->string('tx_hash', 66);
            $t->integer('log_index');
            $t->bigInteger('block_number');
            $t->string('block_hash', 66);
            $t->timestampTz('block_time')->nullable();
            $t->string('from_address', 42);
            $t->string('to_address', 42);
            $t->decimal('amount_base', 78, 0);          // exact, 18 decimals
            // detected | credited | unmatched | reorged | ignored
            $t->string('status', 12)->default('detected');
            $t->uuid('deposit_id')->nullable();
            $t->uuid('tenant_id')->nullable();
            $t->uuid('usdt_wallet_transaction_id')->nullable()->unique();
            $t->timestampTz('finalized_at')->nullable();
            $t->timestampTz('credited_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['chain_id', 'tx_hash', 'log_index']);
            $t->index(['status', 'block_number']);
        });

        Schema::create('crypto_deposits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->foreign('tenant_id')->references('id')->on('tenants');
            $t->uuid('created_by')->nullable();
            $t->integer('chain_id');
            $t->string('token_contract', 42);
            $t->string('address', 42);
            $t->bigInteger('requested_micro');
            $t->bigInteger('amount_micro');             // the exact, unique amount to send
            // pending | paid | expired | cancelled
            $t->string('status', 10)->default('pending');
            $t->timestampTz('expires_at');
            $t->timestampTz('paid_at')->nullable();
            $t->uuid('crypto_transfer_id')->nullable()->unique();
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
            $t->index(['status', 'expires_at']);
        });
        // Two open requests may never ask for the same amount to the same
        // address — that amount is how a payment is told apart.
        DB::statement("CREATE UNIQUE INDEX crypto_deposits_open_amount ON crypto_deposits (address, amount_micro) WHERE status = 'pending'");
        DB::statement('ALTER TABLE crypto_deposits ADD CONSTRAINT crypto_deposits_amount_positive CHECK (amount_micro > 0)');

        Schema::create('chain_checkpoints', function (Blueprint $t) {
            $t->string('name', 40)->primary();
            $t->integer('chain_id');
            $t->bigInteger('last_scanned_block')->nullable();   // everything up to here that is final has been read
            $t->bigInteger('head_block')->nullable();
            $t->bigInteger('finalized_block')->nullable();
            $t->string('provider', 200)->nullable();
            $t->timestampTz('heartbeat_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestampTz('last_error_at')->nullable();
            $t->timestamps();
        });

        // USDT prices — null means "not sold in USDT".
        Schema::table('plans', fn (Blueprint $t) => $t->bigInteger('price_monthly_usdt')->nullable()->after('price_yearly'));
        Schema::table('token_packages', fn (Blueprint $t) => $t->bigInteger('price_usdt')->nullable()->after('price_toman'));
        Schema::table('chatbot_type_prices', fn (Blueprint $t) => $t->bigInteger('price_usdt')->nullable()->after('price_toman'));
        Schema::table('chatbot_index', function (Blueprint $t) {
            $t->bigInteger('monthly_price_usdt')->nullable()->after('monthly_price_toman');
            $t->string('billing_currency', 8)->default('IRT')->after('monthly_price_usdt');
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->uuid('usdt_wallet_transaction_id')->nullable()->unique()->after('wallet_transaction_id');
            $t->foreign('usdt_wallet_transaction_id')->references('id')->on('usdt_wallet_transactions');
        });
    }

    public function down(): void {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropForeign(['usdt_wallet_transaction_id']);
            $t->dropColumn('usdt_wallet_transaction_id');
        });
        Schema::table('chatbot_index', fn (Blueprint $t) => $t->dropColumn(['monthly_price_usdt', 'billing_currency']));
        Schema::table('chatbot_type_prices', fn (Blueprint $t) => $t->dropColumn('price_usdt'));
        Schema::table('token_packages', fn (Blueprint $t) => $t->dropColumn('price_usdt'));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('price_monthly_usdt'));
        Schema::dropIfExists('chain_checkpoints');
        Schema::dropIfExists('crypto_deposits');
        Schema::dropIfExists('crypto_transfers');
        Schema::dropIfExists('usdt_wallet_transactions');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['wallet_balance_usdt', 'billing_currency']));
    }
};
