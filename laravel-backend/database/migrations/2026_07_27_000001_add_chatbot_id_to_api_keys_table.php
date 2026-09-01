<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('api_keys', function (Blueprint $t) {
            // Nullable: existing keys stay tenant-wide (today's convention); new keys
            // created via the admin panel are hard-bound to one chatbot/domain.
            $t->uuid('chatbot_id')->nullable()->after('tenant_id');
        });
    }
    public function down(): void {
        Schema::table('api_keys', function (Blueprint $t) {
            $t->dropColumn('chatbot_id');
        });
    }
};
