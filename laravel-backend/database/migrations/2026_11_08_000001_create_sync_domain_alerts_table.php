<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (chatbot, site that is not its registered domain, kind of
 * destructive request refused). Written by App\Support\SyncOrigin when a
 * WordPress site that does not own a chatbot asks to archive, delete or
 * overwrite its settings — the request is refused and this is how the
 * owner (portal Knowledge page) and the platform (admin activity log) find
 * out. Repeats bump `occurrences` and `last_seen_at` instead of piling up
 * rows, since the same misconfigured site retries on every save.
 *
 * Public schema, keyed by tenant_id: the admin side reads across tenants.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('sync_domain_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('chatbot_id')->index();
            // '' when the request did not say which site it came from.
            $table->string('origin_domain', 255)->default('');
            $table->string('registered_domain', 255)->nullable();
            // settings | clear | delete
            $table->string('action', 20);
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['chatbot_id', 'origin_domain', 'action']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('sync_domain_alerts');
    }
};
