<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four-tier business model (trial/free/pro/business): each plan now
 * carries its own tool allowlist, whether it may purchase token packages at
 * all, whether the "Powered by Haman" widget footer can be removed, and what
 * happens when a tenant on it runs out of quota.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('plans', function (Blueprint $t) {
            if (!Schema::hasColumn('plans', 'allowed_tools')) {
                $t->jsonb('allowed_tools')->default('[]')->after('features');
            }
            if (!Schema::hasColumn('plans', 'can_purchase_tokens')) {
                $t->boolean('can_purchase_tokens')->default(true)->after('is_public');
            }
            if (!Schema::hasColumn('plans', 'branding_removable')) {
                $t->boolean('branding_removable')->default(false)->after('can_purchase_tokens');
            }
            if (!Schema::hasColumn('plans', 'quota_exceeded_behavior')) {
                // stop | degrade | auto_wallet — see QuotaService.
                $t->string('quota_exceeded_behavior', 20)->default('auto_wallet')->after('branding_removable');
            }
        });
    }

    public function down(): void {
        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn(['allowed_tools', 'can_purchase_tokens', 'branding_removable', 'quota_exceeded_behavior']);
        });
    }
};
