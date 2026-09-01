<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // 2025_01_01_000000_create_base_tables.php already creates this same
        // table (guarded the same way) — on production that ran first and
        // this migration's up() never actually executes again, so it's been
        // silently redundant rather than broken. It only surfaced as a real
        // SQLSTATE[42P07] duplicate-table error when running migrate:fresh
        // from an empty database for the first time, in CI.
        if (Schema::hasTable('personal_access_tokens')) return;
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token',64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('personal_access_tokens'); }
};
