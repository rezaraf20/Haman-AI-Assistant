<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PlatformActivity::record() used to silently skip writing anything at all
 * when there was no authenticated user — which meant every cron-triggered
 * destructive action (DropPendingDeletionTenantsCommand's schema drop,
 * PurgeUnverifiedSignupsCommand's hard delete) left no audit trail
 * whatsoever. It now writes a row with user_id null and user_email
 * 'system' in that case, which this column has to actually allow. Raw SQL,
 * not Schema::table()->change() — this codebase has no doctrine/dbal
 * dependency, matching every other ALTER COLUMN in these migrations.
 */
return new class extends Migration {
    public function up(): void {
        DB::statement('ALTER TABLE platform_activity_log ALTER COLUMN user_id DROP NOT NULL');
    }
    public function down(): void {
        DB::statement("DELETE FROM platform_activity_log WHERE user_id IS NULL");
        DB::statement('ALTER TABLE platform_activity_log ALTER COLUMN user_id SET NOT NULL');
    }
};
