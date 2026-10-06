<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            if (!Schema::hasColumn('tenants', 'trial_reminder_3d_sent_at')) {
                $t->timestamp('trial_reminder_3d_sent_at')->nullable()->after('trial_ends_at');
            }
            if (!Schema::hasColumn('tenants', 'trial_reminder_1d_sent_at')) {
                $t->timestamp('trial_reminder_1d_sent_at')->nullable()->after('trial_reminder_3d_sent_at');
            }
        });
    }
    public function down(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['trial_reminder_3d_sent_at', 'trial_reminder_1d_sent_at']);
        });
    }
};
