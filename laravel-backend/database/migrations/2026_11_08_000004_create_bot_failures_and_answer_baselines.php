<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bot_failures — replies where the bot itself failed (App\Support\
 * AnswerOutcome::OURS: empty, fallback, misunderstood). The platform's own
 * view, across tenants (admin → Bot failures). A shop's missing products
 * ("we don't have that") are not here: those go to that shop's demand-gap
 * report as 'unanswered' conversation events.
 *
 * answer_quality_baselines — the measured share of failed replies for a
 * chatbot at a point in time, so a later change has something real to be
 * compared with (the first row: Shop Bot, reclassified from its export).
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('bot_failures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('chatbot_id')->index();
            $table->uuid('conversation_id')->nullable();
            $table->uuid('message_id')->nullable()->unique();
            $table->string('kind', 20);
            $table->text('question')->nullable();
            $table->text('reply')->nullable();
            $table->string('source', 20)->default('live'); // live | backfill
            $table->timestamp('occurred_at')->index();
        });

        Schema::create('answer_quality_baselines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('chatbot_id')->index();
            $table->string('chatbot_name')->nullable();
            $table->timestamp('period_from')->nullable();
            $table->timestamp('period_to')->nullable();
            $table->unsignedInteger('replies');
            $table->unsignedInteger('failed');
            $table->decimal('failed_pct', 5, 1);
            $table->json('by_kind');
            $table->string('source', 120);
            $table->timestamp('measured_at');
        });
    }

    public function down(): void {
        Schema::dropIfExists('answer_quality_baselines');
        Schema::dropIfExists('bot_failures');
    }
};
