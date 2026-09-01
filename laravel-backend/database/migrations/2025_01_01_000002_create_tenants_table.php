<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('tenants', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->string('slug', 100)->unique();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('phone', 50)->nullable();
            $t->string('timezone', 64)->default('UTC');
            $t->string('language', 10)->default('en');
            $t->string('schema_name', 100)->unique();
            $t->uuid('plan_id');
            $t->string('status', 20)->default('trial');
            $t->timestampTz('trial_ends_at')->nullable();
            $t->bigInteger('usage_tokens_current')->default(0);
            $t->bigInteger('usage_messages_current')->default(0);
            $t->jsonb('settings')->default('{}');
            $t->timestampTz('last_active_at')->default(DB::raw('now()'));
            $t->timestamps();
            $t->softDeletes();
            $t->foreign('plan_id')->references('id')->on('plans');
        });
    }
    public function down(): void { Schema::dropIfExists('tenants'); }
};
