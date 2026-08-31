<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->string('melipayamak_username')->nullable();
            // Melipayamak's newer console API authenticates with a GUID-shaped
            // API key (what the platform owner calls the "password" here) via
            // https://console.melipayamak.com/api/send/simple/{key} — not the
            // legacy SOAP username+password scheme. Kept as one field named
            // for what it actually is to future readers.
            $t->string('melipayamak_password')->nullable();
            $t->string('melipayamak_sender')->nullable();
        });

        // One-time seed from what the platform owner gave us directly, same
        // reasoning as the llm_provider_profiles env-seed migration: this
        // value never lived in any .env this app reads, so env() here would
        // be a no-op — set explicitly instead.
        DB::table('platform_settings')->update([
            'melipayamak_username' => '9376808058',
            'melipayamak_password' => '61977149-961b-49a0-b0e3-f88f8c495f4e',
            'melipayamak_sender'   => '50004001680865',
        ]);
    }
    public function down(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->dropColumn(['melipayamak_username', 'melipayamak_password', 'melipayamak_sender']);
        });
    }
};
