<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one outbound OTP SMS costs the platform, so an order-status lookup
 * can be charged to the tenant that caused it (get_order_status, doc-04)
 * instead of quietly landing on the platform's own Melipayamak bill.
 * Admin-set: the real per-SMS price depends on the account and the
 * pattern, and is not something this code can infer.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->unsignedInteger('sms_cost_toman')->default(0);
        });
    }

    public function down(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->dropColumn('sms_cost_toman');
        });
    }
};
