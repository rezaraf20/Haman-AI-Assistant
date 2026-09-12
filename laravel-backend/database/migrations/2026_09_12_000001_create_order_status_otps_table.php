<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * get_order_status (doc-04) — deliberately its own table rather than a
 * reuse of otp_verifications, which exists solely for portal login.
 * Sharing one table would mean a code minted for "show me my order" could
 * be replayed against the portal login form (and vice versa) unless every
 * query on both sides remembered to filter by purpose — exactly the kind
 * of rule that gets forgotten during a later edit. Two tables makes the
 * separation structural instead of a convention.
 *
 * The code is stored HASHED, unlike otp_verifications' plaintext: this
 * table is reachable from an unauthenticated public chat widget, so a
 * read-only leak here must not hand out live codes.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('order_status_otps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('chatbot_id');
            $t->uuid('tenant_id');
            $t->uuid('conversation_id')->nullable();
            // Normalised at write time (09XXXXXXXXX for phones) so the
            // per-contact hourly cap can't be sidestepped by retyping the
            // same number in a different shape.
            $t->string('contact', 255);
            $t->string('contact_type', 10)->default('phone');
            $t->string('code_hash', 255);
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('ip', 45)->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('consumed_at')->nullable();
            $t->timestamps();

            // Serves the verify lookup (latest live code for this contact
            // on this chatbot) and the per-contact hourly send cap.
            $t->index(['chatbot_id', 'contact', 'created_at']);
            $t->index(['contact', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('order_status_otps');
    }
};
