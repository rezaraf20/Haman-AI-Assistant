<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('llm_provider_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            // groq | xai | openai_compatible — all three share the same
            // choices[0].message / usage{} response shape, so one generic
            // request function in rag_service.py handles all of them.
            $t->string('provider');
            $t->string('base_url')->nullable();
            $t->string('model_name');
            // Plaintext, consistent with this app's existing posture
            // (GROQ_API_KEY/XAI_API_KEY are plaintext env vars today, nothing
            // is encrypted at rest anywhere in this codebase yet).
            $t->string('api_key')->nullable();
            $t->smallInteger('priority')->default(0); // lower tries first
            $t->boolean('is_active')->default(true);
            $t->smallInteger('max_tokens_response')->nullable();
            $t->smallInteger('timeout_seconds')->default(30);
            $t->jsonb('extra_headers')->nullable();
            $t->timestampTz('last_success_at')->nullable();
            $t->timestampTz('last_failure_at')->nullable();
            $t->smallInteger('consecutive_failures')->default(0);
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('llm_provider_profiles');
    }
};
