<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // This migration just ensures the helper function exists
        // Tenant schemas are created by TenantService::createSchema()
        DB::statement("
            CREATE OR REPLACE FUNCTION hamman_create_schema(p_schema text)
            RETURNS void AS \$func\$
            BEGIN
                EXECUTE 'CREATE SCHEMA IF NOT EXISTS ' || quote_ident(p_schema);
            END;
            \$func\$ LANGUAGE plpgsql
        ");
    }
    public function down(): void {
        DB::statement('DROP FUNCTION IF EXISTS hamman_create_schema(text)');
    }
};
