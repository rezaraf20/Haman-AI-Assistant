<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Sent once" flags for NotifyUnconnectedChatbotsCommand — same shape as
 * tenants.trial_reminder_3d_sent_at/trial_reminder_1d_sent_at, just keyed on
 * the chatbot (chatbot_index, not tenants) since a tenant can own more than
 * one chatbot and each one's connection status is independent.
 */
return new class extends Migration {
    public function up(): void {
        DB::statement("ALTER TABLE chatbot_index ADD COLUMN IF NOT EXISTS connection_alert_first_sent_at TIMESTAMPTZ");
        DB::statement("ALTER TABLE chatbot_index ADD COLUMN IF NOT EXISTS connection_alert_second_sent_at TIMESTAMPTZ");
    }
    public function down(): void {
        DB::statement("ALTER TABLE chatbot_index DROP COLUMN IF EXISTS connection_alert_first_sent_at");
        DB::statement("ALTER TABLE chatbot_index DROP COLUMN IF EXISTS connection_alert_second_sent_at");
    }
};
