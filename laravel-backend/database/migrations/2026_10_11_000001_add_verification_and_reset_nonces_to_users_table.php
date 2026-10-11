<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's signed URLs are stateless HMACs — nothing server-side tracks
 * which ones have been "used up", so a resend (email verification) or a
 * second reset request (password) would otherwise leave the PREVIOUS
 * link just as valid as the new one forever (until its own 48h/1h expiry).
 * These two nonces are the single-use guard: each send/request overwrites
 * the stored value, and the controller checks the incoming link's nonce
 * against the CURRENT one — an older link's nonce no longer matches the
 * moment a newer one is issued, and a successful verify/reset clears it
 * outright so the same link can never be replayed either.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_verification_nonce', 64)->nullable();
            $table->string('password_reset_nonce', 64)->nullable();
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verification_nonce', 'password_reset_nonce']);
        });
    }
};
