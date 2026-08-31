<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('chatbot_index', function (Blueprint $t) {
            // What the customer pays, from their own wallet, to renew this one
            // chatbot for another cycle — set by the admin per chatbot (a
            // customer buying 2-3 chatbots at different tiers is normal, so
            // this lives per-chatbot, not just per-plan).
            $t->bigInteger('monthly_price_toman')->default(0)->after('expires_at');
        });
    }
    public function down(): void {
        Schema::table('chatbot_index', function (Blueprint $t) {
            $t->dropColumn('monthly_price_toman');
        });
    }
};
