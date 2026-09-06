<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backs the auto-disable-on-repeated-failure rule: a provider with
// consecutive_failures past the threshold (see llm_provider_service.py)
// gets is_active flipped to false automatically, with disabled_reason
// recording why. disabled_notified_at is separate from disabled_reason so
// the Laravel-side notifier (hamman:notify-disabled-providers, see
// routes/console.php) can tell "already alerted the admin about this" from
// "just got disabled, still needs alerting" without re-notifying every run.
return new class extends Migration {
    public function up(): void {
        Schema::table('llm_provider_profiles', function (Blueprint $t) {
            $t->text('disabled_reason')->nullable()->after('is_active');
            $t->timestampTz('disabled_notified_at')->nullable()->after('disabled_reason');
        });
    }
    public function down(): void {
        Schema::table('llm_provider_profiles', function (Blueprint $t) {
            $t->dropColumn(['disabled_reason', 'disabled_notified_at']);
        });
    }
};
