<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// key_hash (bcrypt, one-way) stays the source of truth for auth verification
// in AuthenticateTenantApiKey — untouched. This column is purely so the full
// key can be shown again on demand (admin + customer both asked for it,
// since the one-time-reveal-then-gone model means a lost copy = a support
// ticket). Nullable: existing keys issued before this column existed have no
// reversible copy and simply can't be re-revealed — the UI must handle that.
return new class extends Migration {
    public function up(): void {
        Schema::table('api_keys', function (Blueprint $t) {
            $t->text('key_encrypted')->nullable()->after('key_hash');
        });
    }
    public function down(): void {
        Schema::table('api_keys', function (Blueprint $t) {
            $t->dropColumn('key_encrypted');
        });
    }
};
