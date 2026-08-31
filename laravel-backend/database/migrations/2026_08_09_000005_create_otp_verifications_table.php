<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('otp_verifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('phone', 20);
            $t->string('code', 8);
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestampTz('expires_at');
            $t->timestampTz('consumed_at')->nullable();
            $t->timestamps();

            $t->index(['phone', 'consumed_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('otp_verifications');
    }
};
