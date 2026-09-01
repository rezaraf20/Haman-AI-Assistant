<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// One-time data backfill, not a Seeder — Seeders run on every container start
// via the entrypoint's `db:seed --force`, which would either duplicate rows or
// (if made idempotent) silently reset an admin's Filament edits back to the
// env-var defaults on every deploy. A migration runs exactly once, tracked
// like any schema change, which is what a "cut over from static config"
// backfill actually needs.
return new class extends Migration {
    // NOTE: GROQ_API_KEY/XAI_API_KEY only ever lived in python-ai-service/.env,
    // never in this (laravel-backend) service's .env — env() below is a no-op
    // on this deployment and the two rows were inserted by hand once via psql
    // instead. Left in place as documentation of intent and in case a future
    // environment genuinely does have these vars set on the Laravel side.
    public function up(): void {
        if (DB::table('llm_provider_profiles')->exists()) return;

        $now = now();
        $rows = [];

        if ($groqKey = env('GROQ_API_KEY')) {
            $rows[] = [
                'id' => Str::uuid()->toString(),
                'name' => 'Groq (llama-3.1-8b-instant)',
                'provider' => 'groq',
                'base_url' => 'https://api.groq.com/openai/v1',
                'model_name' => 'llama-3.1-8b-instant',
                'api_key' => $groqKey,
                'priority' => 1,
                'is_active' => true,
                'timeout_seconds' => 30,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if ($xaiKey = env('XAI_API_KEY')) {
            $rows[] = [
                'id' => Str::uuid()->toString(),
                'name' => 'xAI (' . (env('XAI_CHAT_MODEL') ?: 'grok-2-latest') . ')',
                'provider' => 'xai',
                'base_url' => 'https://api.x.ai/v1',
                'model_name' => env('XAI_CHAT_MODEL') ?: 'grok-2-latest',
                'api_key' => $xaiKey,
                'priority' => 2,
                'is_active' => true,
                'timeout_seconds' => 30,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        if ($rows) DB::table('llm_provider_profiles')->insert($rows);
    }
    public function down(): void {
        // No-op: this is a one-time data backfill, not reversible schema.
    }
};
