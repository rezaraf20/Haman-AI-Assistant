<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when CheckTrialCostCommand last alerted admins, the same way
 * LlmProviderProfile.disabled_notified_at stops NotifyDisabledProvidersCommand
 * from repeating itself — a DB column rather than a cache key, since this only
 * needs to be checked a few times an hour and must survive a cache flush or a
 * worker restart without re-alerting.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('platform_settings', 'trial_cost_alert_sent_at')) {
                $t->timestamp('trial_cost_alert_sent_at')->nullable();
            }
        });
    }

    public function down(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->dropColumn('trial_cost_alert_sent_at');
        });
    }
};
