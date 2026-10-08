<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every "Test Connection" click from the WordPress plugin, success or
 * failure — see ConnectionTestController. Public schema, not per-tenant:
 * a request that fails auth never resolves a tenant at all (that's the
 * whole point — a missing/invalid key has to be loggable too), and the
 * admin's "never connected" widget needs to query across every tenant at
 * once.
 *
 * Before this (2026-10-07 investigation): a merchant's plugin could fail
 * to connect forever and leave zero trace anywhere we could see — the
 * only visible fact was api_keys.last_used_at staying null, which told us
 * THAT it never worked, never whether anyone had even tried.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('connection_tests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->nullable();
            $t->uuid('chatbot_id')->nullable();
            $t->uuid('api_key_id')->nullable();
            $t->string('outcome', 30);
            $t->string('message', 255);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['tenant_id', 'created_at']);
            $t->index(['outcome', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('connection_tests');
    }
};
