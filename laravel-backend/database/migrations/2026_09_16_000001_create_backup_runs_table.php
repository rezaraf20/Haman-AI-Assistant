<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per backup attempt, successful or not.
 *
 * Failures are recorded, not just successes — a backup system that only
 * writes a row when it works looks identical to one that has not run for a
 * month. The admin panel reads the latest row of either kind, so "last
 * backup failed" and "last backup was 9 days ago" are both visible.
 *
 * verified_at / verified_rows are stamped by hamman:verify-backup, which
 * restores the dump into a scratch database and counts what came back.
 * A backup nobody has restored is a guess, so the panel shows the
 * verification separately from the dump.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('backup_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind', 20)->default('daily');       // daily | weekly | manual
            $t->string('status', 20);                        // running | success | failed
            $t->string('filename', 255)->nullable();
            $t->bigInteger('bytes')->nullable();
            $t->string('destination', 30)->nullable();       // local_only | s3
            $t->string('remote_path', 500)->nullable();
            $t->text('error')->nullable();
            $t->integer('duration_ms')->nullable();

            // Stamped by a real restore, not by the dump itself.
            $t->timestampTz('verified_at')->nullable();
            $t->jsonb('verified_counts')->nullable();
            $t->text('verify_error')->nullable();

            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();

            $t->index(['status', 'started_at']);
            $t->index('started_at');
        });
    }

    public function down(): void {
        Schema::dropIfExists('backup_runs');
    }
};
