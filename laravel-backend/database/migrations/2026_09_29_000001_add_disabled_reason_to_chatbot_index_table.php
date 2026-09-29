<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('chatbot_index', function (Blueprint $t) {
            // Set whenever something OTHER than the merchant deactivates a
            // chatbot (expires_at passed, a trial chatbot's message cap was
            // hit) — never set for an admin's own manual suspend, which needs
            // no explanation shown back to the merchant. MyChatbots.php reads
            // this to show a real reason (and, for the trial values, an
            // upgrade path) instead of a bare "inactive" icon.
            $t->string('disabled_reason', 50)->nullable()->after('is_active');
        });
    }
    public function down(): void {
        Schema::table('chatbot_index', function (Blueprint $t) {
            $t->dropColumn('disabled_reason');
        });
    }
};
