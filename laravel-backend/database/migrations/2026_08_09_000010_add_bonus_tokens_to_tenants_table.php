<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Persistent purchased-token credit — unlike usage_tokens_current (resets
// monthly, see hamman:reset-usage), this balance carries over and is only
// drawn down once the plan's own monthly quota is exhausted. See
// Tenant::isTokenQuotaExceeded() and TenantService::incrementUsage().
return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->bigInteger('bonus_tokens')->default(0)->after('usage_messages_current');
        });
    }
    public function down(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('bonus_tokens');
        });
    }
};
