<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets the platform actually know its own margin per message. Priced in
// Toman (not USD) to match every other money figure in this app — admin
// manually converts whatever Groq/xAI actually bill in, same as plan prices
// were hand-converted from USD earlier. Default 0 = "not priced yet", so an
// unconfigured profile just costs nothing rather than breaking anything.
return new class extends Migration {
    public function up(): void {
        Schema::table('llm_provider_profiles', function (Blueprint $t) {
            $t->decimal('input_price_per_1m_toman', 12, 2)->default(0)->after('model_name');
            $t->decimal('output_price_per_1m_toman', 12, 2)->default(0)->after('input_price_per_1m_toman');
        });
    }
    public function down(): void {
        Schema::table('llm_provider_profiles', function (Blueprint $t) {
            $t->dropColumn(['input_price_per_1m_toman', 'output_price_per_1m_toman']);
        });
    }
};
