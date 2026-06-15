<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Enable extensions
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Cache table
        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $t) {
                $t->string('key')->primary();
                $t->mediumText('value');
                $t->integer('expiration');
            });
            Schema::create('cache_locks', function (Blueprint $t) {
                $t->string('key')->primary();
                $t->string('owner');
                $t->integer('expiration');
            });
        }

        // Jobs table
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $t) {
                $t->id();
                $t->string('queue')->index();
                $t->longText('payload');
                $t->unsignedTinyInteger('attempts');
                $t->unsignedInteger('reserved_at')->nullable();
                $t->unsignedInteger('available_at');
                $t->unsignedInteger('created_at');
            });
            Schema::create('job_batches', function (Blueprint $t) {
                $t->string('id')->primary();
                $t->string('name');
                $t->integer('total_jobs');
                $t->integer('pending_jobs');
                $t->integer('failed_jobs');
                $t->longText('failed_job_ids');
                $t->mediumText('options')->nullable();
                $t->integer('cancelled_at')->nullable();
                $t->integer('created_at');
                $t->integer('finished_at')->nullable();
            });
            Schema::create('failed_jobs', function (Blueprint $t) {
                $t->id();
                $t->string('uuid')->unique();
                $t->text('connection');
                $t->text('queue');
                $t->longText('payload');
                $t->longText('exception');
                $t->timestamp('failed_at')->useCurrent();
            });
        }

        // Users table with UUID
        if (!Schema::hasTable('users')) {
            DB::statement("
                CREATE TABLE users (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    tenant_id UUID,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    email_verified_at TIMESTAMPTZ,
                    password VARCHAR(255),
                    password_hash VARCHAR(255),
                    role VARCHAR(20) NOT NULL DEFAULT 'viewer',
                    avatar_url VARCHAR(500),
                    remember_token VARCHAR(100),
                    last_login_at TIMESTAMPTZ,
                    last_login_ip VARCHAR(45),
                    failed_login_count SMALLINT NOT NULL DEFAULT 0,
                    locked_until TIMESTAMPTZ,
                    preferences JSONB NOT NULL DEFAULT '{}',
                    created_at TIMESTAMPTZ,
                    updated_at TIMESTAMPTZ,
                    deleted_at TIMESTAMPTZ
                )
            ");
        }

        // Personal access tokens with UUID tokenable_id
        if (!Schema::hasTable('personal_access_tokens')) {
            DB::statement("
                CREATE TABLE personal_access_tokens (
                    id BIGSERIAL PRIMARY KEY,
                    tokenable_type VARCHAR(255) NOT NULL,
                    tokenable_id UUID NOT NULL,
                    name TEXT NOT NULL,
                    token VARCHAR(64) NOT NULL UNIQUE,
                    abilities TEXT,
                    last_used_at TIMESTAMP,
                    expires_at TIMESTAMP,
                    created_at TIMESTAMP,
                    updated_at TIMESTAMP
                )
            ");
            DB::statement('CREATE INDEX ON personal_access_tokens(tokenable_type, tokenable_id)');
        }
    }

    public function down(): void {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
