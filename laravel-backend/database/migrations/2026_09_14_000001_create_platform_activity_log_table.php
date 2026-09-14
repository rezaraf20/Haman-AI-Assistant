<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Everything a platform user does, in one append-only table.
 *
 * Supersedes platform_audit_log, which shipped a day earlier with a single
 * `changes` column shaped {"field":{"before":x,"after":y}}. Separate before
 * and after columns are the better shape: they can be selected, indexed and
 * nulled independently, which is what the 18-month retention rule needs —
 * it blanks the two payload columns and keeps the row.
 *
 * Renamed rather than recreated so the audit trail is continuous; any rows
 * already written are carried across and split into the new shape.
 *
 * Public schema on purpose: this is a record ABOUT staff, and it has to
 * outlive any tenant schema being dropped.
 *
 * Nothing in the application deletes from this table. There is no UI action,
 * no model with a delete() path, and the retention command below only ever
 * issues UPDATE.
 */
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('platform_audit_log') && !Schema::hasTable('platform_activity_log')) {
            Schema::rename('platform_audit_log', 'platform_activity_log');

            // Postgres keeps the old index names across a table rename.
            foreach ([
                'platform_audit_log_user_id_created_at_index'   => 'platform_activity_log_user_id_created_at_index',
                'platform_audit_log_tenant_id_created_at_index' => 'platform_activity_log_tenant_id_created_at_index',
                'platform_audit_log_action_created_at_index'    => 'platform_activity_log_action_created_at_index',
                'platform_audit_log_pkey'                       => 'platform_activity_log_pkey',
            ] as $from => $to) {
                DB::statement("ALTER INDEX IF EXISTS \"{$from}\" RENAME TO \"{$to}\"");
            }
        }

        if (!Schema::hasTable('platform_activity_log')) {
            Schema::create('platform_activity_log', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('user_id');
                $t->string('user_email', 255)->nullable();
                $t->string('platform_role', 20)->nullable();
                $t->string('action', 60);
                $t->uuid('tenant_id')->nullable();
                $t->string('subject_type', 60)->nullable();
                $t->string('subject_id', 255)->nullable();
                $t->string('reason', 60)->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestampTz('created_at')->useCurrent();

                $t->index(['user_id', 'created_at']);
                $t->index(['tenant_id', 'created_at']);
                $t->index(['action', 'created_at']);
            });
        }

        Schema::table('platform_activity_log', function (Blueprint $t) {
            if (!Schema::hasColumn('platform_activity_log', 'before'))     $t->jsonb('before')->nullable();
            if (!Schema::hasColumn('platform_activity_log', 'after'))      $t->jsonb('after')->nullable();
            if (!Schema::hasColumn('platform_activity_log', 'user_agent')) $t->string('user_agent', 500)->nullable();
        });

        // Carry the old single-column shape across, if any rows exist.
        if (Schema::hasColumn('platform_activity_log', 'changes')) {
            DB::statement(<<<'SQL'
                UPDATE platform_activity_log
                SET "before" = (SELECT jsonb_object_agg(k, v -> 'before') FROM jsonb_each("changes") AS e(k, v)),
                    "after"  = (SELECT jsonb_object_agg(k, v -> 'after')  FROM jsonb_each("changes") AS e(k, v))
                WHERE "changes" IS NOT NULL
                  AND jsonb_typeof("changes") = 'object'
                  AND "before" IS NULL
            SQL);

            Schema::table('platform_activity_log', fn (Blueprint $t) => $t->dropColumn('changes'));
        }

        // The activity-log page filters by these, and the retention command
        // scans by created_at alone.
        DB::statement('CREATE INDEX IF NOT EXISTS platform_activity_log_created_at_index ON platform_activity_log (created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS platform_activity_log_subject_index ON platform_activity_log (subject_type, subject_id)');
    }

    public function down(): void {
        // Deliberately not reversible into the old shape: dropping this
        // table would destroy the record of what staff did, which is the
        // one thing it exists to prevent.
    }
};
