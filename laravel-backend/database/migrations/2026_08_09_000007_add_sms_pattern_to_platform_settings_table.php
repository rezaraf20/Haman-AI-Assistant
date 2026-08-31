<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->boolean('melipayamak_use_pattern')->default(false);
            // Melipayamak calls this "bodyId" — the numeric ID of a pattern
            // template registered in their panel (e.g. "کد تایید شما: %code%"),
            // required by carriers for OTP delivery in Iran in many cases
            // where plain SMS gets filtered as advertising.
            $t->string('melipayamak_pattern_id')->nullable();
        });
    }
    public function down(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->dropColumn(['melipayamak_use_pattern', 'melipayamak_pattern_id']);
        });
    }
};
