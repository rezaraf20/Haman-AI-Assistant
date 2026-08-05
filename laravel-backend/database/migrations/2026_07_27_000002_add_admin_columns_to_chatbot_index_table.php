<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::statement("ALTER TABLE chatbot_index ADD COLUMN IF NOT EXISTS name VARCHAR(255)");
        DB::statement("ALTER TABLE chatbot_index ADD COLUMN IF NOT EXISTS primary_domain VARCHAR(255)");
        DB::statement("ALTER TABLE chatbot_index ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ");
    }
    public function down(): void {
        DB::statement("ALTER TABLE chatbot_index DROP COLUMN IF EXISTS name");
        DB::statement("ALTER TABLE chatbot_index DROP COLUMN IF EXISTS primary_domain");
        DB::statement("ALTER TABLE chatbot_index DROP COLUMN IF EXISTS expires_at");
    }
};
