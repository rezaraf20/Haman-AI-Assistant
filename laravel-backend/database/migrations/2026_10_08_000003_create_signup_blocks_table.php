<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every signup refused outright by SignupRisk::blockReason() — honeypot
 * filled, or submitted faster than any human could have (2026-10-08,
 * "honeypot should block, not just tag"). No tenant/user/schema is ever
 * created for these, so without this table a blocked attempt would leave
 * zero trace anywhere — exactly the "silent broken trap costs a real
 * customer" risk the admin ratio widget exists to catch. Public schema:
 * a blocked request never resolves a tenant, same reasoning as
 * connection_tests.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('signup_blocks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reason', 30);
            $t->string('source', 20);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['created_at']);
            $t->index(['reason', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('signup_blocks');
    }
};
