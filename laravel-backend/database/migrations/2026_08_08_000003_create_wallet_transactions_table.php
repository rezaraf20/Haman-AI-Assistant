<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->foreign('tenant_id')->references('id')->on('tenants');
            // topup | plan_charge | admin_adjustment | refund
            $t->string('type');
            // Signed: positive = credit, negative = debit. Whole Toman — this
            // business has no sub-Toman transactions, no need for Rial subunits
            // internally (only Zarinpal's own API wants amount * 10 = Rial).
            $t->bigInteger('amount_toman');
            // Snapshot of the balance immediately after this transaction —
            // cheap audit/dispute resolution without replaying the whole ledger.
            $t->bigInteger('balance_after_toman');
            // pending | completed | failed | reversed
            $t->string('status')->default('pending');
            $t->string('gateway')->nullable(); // zarinpal
            $t->string('gateway_authority')->nullable()->unique();
            $t->string('gateway_ref_id')->nullable();
            $t->text('description')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->uuid('created_by')->nullable(); // admin user, for manual adjustments
            $t->timestamps();

            $t->index(['tenant_id', 'created_at']);
            $t->index('status');
        });
        Schema::table('tenants', function (Blueprint $t) {
            // Read cache only — source of truth is SUM(wallet_transactions
            // WHERE status='completed'). Recomputed inside the same DB
            // transaction that flips a transaction to completed.
            $t->bigInteger('wallet_balance_toman')->default(0)->after('usage_messages_current');
        });
    }
    public function down(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('wallet_balance_toman');
        });
        Schema::dropIfExists('wallet_transactions');
    }
};
