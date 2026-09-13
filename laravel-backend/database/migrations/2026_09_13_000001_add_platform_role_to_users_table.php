<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform staff roles, kept deliberately separate from users.role.
 *
 * users.role is the role INSIDE a tenant ('owner' for every customer's
 * first user). A previous vulnerability granted the cross-tenant admin
 * panel to role==='owner', which meant every paying customer could log
 * into it. platform_role exists so the two can never be confused again:
 * it is null for every tenant user, and nothing in a tenant-facing code
 * path can set it.
 *
 * is_platform_admin is kept and stays in sync (see User::booted()) so
 * existing readers keep working; platform_role is the source of truth.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            // null | 'support' | 'admin'
            $t->string('platform_role', 20)->nullable()->after('is_platform_admin');
            // Deactivating a staff account must lock it out immediately —
            // canAccessPanel() reads this on every request.
            $t->boolean('platform_is_active')->default(true)->after('platform_role');
            $t->date('platform_started_at')->nullable()->after('platform_is_active');
            // The label a customer sees in a ticket reply, e.g. "پشتیبان ۱",
            // so staff need not expose their own name.
            $t->string('platform_display_id', 50)->nullable()->after('platform_started_at');
        });

        // Everyone who was a platform admin becomes one under the new column.
        DB::table('users')->where('is_platform_admin', true)->update(['platform_role' => 'admin']);

        Schema::table('users', function (Blueprint $t) {
            $t->index(['platform_role', 'platform_is_active']);
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['platform_role', 'platform_is_active']);
            $t->dropColumn(['platform_role', 'platform_is_active', 'platform_started_at', 'platform_display_id']);
        });
    }
};
