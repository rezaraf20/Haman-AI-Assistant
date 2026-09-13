<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What platform staff did inside a customer's account.
 *
 * Support genuinely needs to read conversations (they contain the problem
 * the customer is calling about) and to change widget settings (the most
 * common request there is). Both are also exactly the sort of access that
 * must be reviewable afterwards — so every one of them lands here, with
 * the reason the operator gave and, for a settings change, the values
 * before and after.
 *
 * Public schema on purpose: this is a record ABOUT staff, and it must
 * survive a tenant schema being dropped.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('platform_audit_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('user_email', 255)->nullable();   // denormalised: survives the user being deleted
            $t->string('platform_role', 20)->nullable();
            $t->string('action', 60);                    // conversation_viewed | contact_revealed | widget_settings_changed | ...
            $t->uuid('tenant_id')->nullable();
            $t->string('subject_type', 60)->nullable();  // conversation | chatbot | ticket ...
            $t->string('subject_id', 255)->nullable();
            $t->string('reason', 60)->nullable();        // ticket_review | error_report | customer_request
            $t->jsonb('changes')->nullable();            // {"field": {"before": ..., "after": ...}}
            $t->string('ip', 45)->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['user_id', 'created_at']);
            $t->index(['tenant_id', 'created_at']);
            $t->index(['action', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('platform_audit_log');
    }
};
