<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// Backs the "N new customers" nav badge in TenantResource. Null = admin
// hasn't seen this tenant yet (cleared the first time they open the Tenants
// list). Existing rows are backfilled to now() below so the badge only ever
// counts signups from this point forward, not the entire existing customer base.
return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->timestamp('admin_seen_at')->nullable()->after('last_active_at');
        });
        DB::table('tenants')->update(['admin_seen_at' => now()]);
    }
    public function down(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('admin_seen_at');
        });
    }
};
