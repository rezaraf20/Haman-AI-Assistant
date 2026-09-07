<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * notifications.data was created as plain TEXT (Laravel's historical
 * notifications:table stub), but Filament's panel notification-bell query
 * runs Postgres's `->>'format'` JSON operator directly against this
 * column — that operator only exists for json/jsonb, not text, so every
 * single panel page load 500s with "operator does not exist: text ->>
 * unknown" the moment this table has RLS/typing checked (Postgres
 * type-checks at parse time regardless of row count). Real production
 * 500 reported by Reza; same class of bug as the earlier
 * products.tags fix (see 2025-...-fix products.tags migration).
 */
return new class extends Migration {
    public function up(): void {
        DB::statement("ALTER TABLE notifications ALTER COLUMN data TYPE JSONB USING data::jsonb");
    }
    public function down(): void {
        DB::statement("ALTER TABLE notifications ALTER COLUMN data TYPE TEXT USING data::text");
    }
};
