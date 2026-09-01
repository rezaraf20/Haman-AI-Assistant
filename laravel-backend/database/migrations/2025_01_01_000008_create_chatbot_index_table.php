<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::statement("
            CREATE TABLE IF NOT EXISTS chatbot_index (
                chatbot_id  UUID PRIMARY KEY,
                tenant_id   UUID NOT NULL,
                schema_name VARCHAR(100) NOT NULL,
                is_active   BOOLEAN NOT NULL DEFAULT true,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_chatbot_index_tenant ON chatbot_index(tenant_id)");
    }
    public function down(): void {
        DB::statement("DROP TABLE IF EXISTS chatbot_index");
    }
};
