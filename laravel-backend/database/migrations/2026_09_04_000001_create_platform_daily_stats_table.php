<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public-schema counterpart to each tenant's own analytics_daily — the admin
 * dashboard's platform-wide charts (daily message volume, revenue vs. cost)
 * need one cross-tenant row per day, not a per-tenant table that would
 * require scanning every tenant schema on every dashboard load.
 * AggregateAnalyticsJob upserts into both in the same run.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('platform_daily_stats', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->bigInteger('total_messages')->default(0);
            $table->bigInteger('total_conversations')->default(0);
            $table->bigInteger('total_tokens')->default(0);
            $table->decimal('cost_toman', 14, 4)->default(0);
            $table->decimal('revenue_toman', 14, 4)->default(0);
            $table->bigInteger('unanswered_count')->default(0);
            $table->bigInteger('active_tenants')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('platform_daily_stats');
    }
};
