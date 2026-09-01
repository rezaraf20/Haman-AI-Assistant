<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// Single-row settings table, editable from the admin panel (see
// app/Filament/Pages/Settings.php) instead of requiring a .env edit + full
// image rebuild every time Reza needs to change a Zarinpal credential.
return new class extends Migration {
    public function up(): void {
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('zarinpal_merchant_id')->nullable();
            $t->boolean('zarinpal_sandbox')->default(true);
            $t->timestamps();
        });

        // Seed the single row once, carrying over whatever was in .env so
        // existing sandbox config isn't silently reset by this migration.
        DB::table('platform_settings')->insert([
            'id'                   => \Illuminate\Support\Str::uuid()->toString(),
            'zarinpal_merchant_id' => env('ZARINPAL_MERCHANT_ID'),
            'zarinpal_sandbox'     => filter_var(env('ZARINPAL_SANDBOX', true), FILTER_VALIDATE_BOOLEAN),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }
    public function down(): void {
        Schema::dropIfExists('platform_settings');
    }
};
