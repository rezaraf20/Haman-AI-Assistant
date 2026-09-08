<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tool calling needs a genuinely capable model (llama-3.1-8b-instant is
 * known-weak at it) — a real, separate lever from the regular chat
 * failover chain's priority order, since the cheapest/fastest model for
 * plain answers isn't necessarily the one an admin wants spending real
 * money on a multi-step tool loop. See llm_provider_service.py's
 * get_active_tool_calling_profiles().
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('llm_provider_profiles', function (Blueprint $t) {
            $t->boolean('supports_tool_calling')->default(false)->after('is_active');
        });
    }
    public function down(): void {
        Schema::table('llm_provider_profiles', function (Blueprint $t) {
            $t->dropColumn('supports_tool_calling');
        });
    }
};
