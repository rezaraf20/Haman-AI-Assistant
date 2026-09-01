<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->uuid('tenant_id');
            $t->uuid('plan_id');
            $t->string('stripe_subscription_id')->nullable()->unique();
            $t->string('status', 30)->default('trialing');
            $t->string('billing_cycle', 10)->default('monthly');
            $t->timestampTz('current_period_end')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->timestampTz('cancelled_at')->nullable();
            $t->jsonb('metadata')->default('{}');
            $t->timestamps();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign('plan_id')->references('id')->on('plans');
        });
    }
    public function down(): void { Schema::dropIfExists('subscriptions'); }
};
