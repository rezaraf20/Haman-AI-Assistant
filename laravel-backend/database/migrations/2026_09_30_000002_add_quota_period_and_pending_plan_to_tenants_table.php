<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Per-tenant-anchored quota periods (the monthly included allowance resets
 * on each tenant's own subscription anniversary, not the 1st of the
 * calendar month) and end-of-cycle plan downgrades (a downgrade must not
 * take effect mid-cycle — see MyChatbots/Plan change page).
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            if (!Schema::hasColumn('tenants', 'quota_period_started_at')) {
                $t->timestamp('quota_period_started_at')->nullable()->after('usage_messages_current');
            }
            if (!Schema::hasColumn('tenants', 'pending_plan_id')) {
                $t->uuid('pending_plan_id')->nullable()->after('plan_id');
            }
            if (!Schema::hasColumn('tenants', 'pending_plan_effective_at')) {
                $t->timestamp('pending_plan_effective_at')->nullable()->after('pending_plan_id');
            }
        });

        // Every existing tenant's period starts now — there is no real
        // "subscription anniversary" to recover for a tenant who predates
        // this column, and today is at least a safe, real anchor rather
        // than an arbitrary guess.
        DB::table('tenants')->whereNull('quota_period_started_at')->update(['quota_period_started_at' => now()]);
    }

    public function down(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['quota_period_started_at', 'pending_plan_id', 'pending_plan_effective_at']);
        });
    }
};
