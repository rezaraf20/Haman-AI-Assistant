<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * The billing core: invoices and a paid-until date.
 *
 * Before this, a paid plan was charged exactly once — the prorated
 * difference at upgrade — and never again (nothing ever debited
 * price_monthly a second time). Renewal now works like this:
 *
 *   - tenants.plan_paid_until is the end of the period the current paid
 *     plan has been paid for. NULL means "not billed": a free/trial plan,
 *     or a paid plan an admin granted before billing existed. Existing
 *     tenants are deliberately left NULL — billing must not start on its
 *     own for a customer who was given a plan by hand; it starts when they
 *     upgrade themselves or an admin sets the date (an audited edit).
 *   - Ahead of that date an invoice is issued for the next period, and on
 *     the date it is charged from the wallet automatically. Unpaid past the
 *     grace period, the invoice is voided and the plan drops to free.
 *
 * Paid chatbots (chatbot_index.monthly_price_toman > 0) renew the same way,
 * against their expires_at.
 *
 * amount is in the currency's minor unit — whole Toman for IRT. The
 * currency column is there so a second wallet currency (USDT) uses this
 * same table instead of a parallel one.
 *
 * Plan prices become integers: every price in this system is whole Toman,
 * and a decimal(10,2) read back as float made proration floating-point.
 * Every existing price is whole already (checked on production 2026-11-09).
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->timestampTz('plan_paid_until')->nullable()->after('pending_plan_effective_at');
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            // plan_renewal | chatbot_renewal
            $t->string('purpose', 30);
            // tenant id for plan_renewal, chatbot id for chatbot_renewal —
            // what is being renewed; with period_start it identifies the
            // invoice, so issuing twice cannot create two.
            $t->string('subject_id', 64);
            $t->uuid('plan_id')->nullable();
            $t->string('description', 500);
            $t->string('currency', 8)->default('IRT');
            $t->bigInteger('amount');
            $t->timestampTz('period_start');
            $t->timestampTz('period_end');
            // open | paid | void
            $t->string('status', 10)->default('open');
            $t->timestampTz('issued_at');
            $t->timestampTz('due_at');
            $t->timestampTz('paid_at')->nullable();
            $t->timestampTz('voided_at')->nullable();
            $t->string('void_reason', 40)->nullable();
            $t->uuid('wallet_transaction_id')->nullable()->unique();
            $t->foreign('wallet_transaction_id')->references('id')->on('wallet_transactions');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('last_attempt_at')->nullable();
            $t->string('last_failure', 40)->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'issued_at']);
            $t->index(['status', 'due_at']);
        });
        // Human-facing sequential number (the id is the uuid key).
        DB::statement('CREATE SEQUENCE invoices_number_seq START 1001');
        DB::statement("ALTER TABLE invoices ADD COLUMN number bigint NOT NULL DEFAULT nextval('invoices_number_seq')");
        DB::statement('ALTER SEQUENCE invoices_number_seq OWNED BY invoices.number');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_number_unique UNIQUE (number)');
        // One live invoice per thing per period. A voided one (plan changed,
        // unpaid) does not block issuing its replacement.
        DB::statement("CREATE UNIQUE INDEX invoices_one_per_period ON invoices (purpose, subject_id, period_start) WHERE status <> 'void'");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_amount_positive CHECK (amount > 0)");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_known CHECK (status IN ('open','paid','void'))");

        DB::statement('ALTER TABLE plans ALTER COLUMN price_monthly TYPE bigint USING round(price_monthly)');
        DB::statement('ALTER TABLE plans ALTER COLUMN price_yearly TYPE bigint USING round(price_yearly)');
    }

    public function down(): void {
        DB::statement('ALTER TABLE plans ALTER COLUMN price_monthly TYPE numeric(10,2)');
        DB::statement('ALTER TABLE plans ALTER COLUMN price_yearly TYPE numeric(10,2)');
        Schema::dropIfExists('invoices');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('plan_paid_until'));
    }
};
