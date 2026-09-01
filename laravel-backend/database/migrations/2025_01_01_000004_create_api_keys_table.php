<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('api_keys', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->uuid('tenant_id');
            $t->uuid('created_by')->nullable();
            $t->string('name');
            $t->string('key_prefix', 12);
            $t->string('key_hash')->unique();
            $t->jsonb('scopes')->default('["read","write","sync","chat"]');
            $t->timestampTz('last_used_at')->nullable();
            $t->string('last_used_ip', 45)->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        DB::statement('CREATE INDEX idx_api_keys_prefix ON api_keys(key_prefix)');
    }
    public function down(): void { Schema::dropIfExists('api_keys'); }
};
