<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Security fix: User::canAccessPanel() previously granted the admin panel
// (full cross-tenant visibility) to anyone with role='owner' — but 'owner'
// only ever meant "this is the first/primary user of their own tenant
// account" (see TenantService::createTenant()/createTenantForPhoneAuth(),
// which set it on every new signup). Any paying customer's own account
// could log into /admin and see every other tenant's data. This column is
// the real, deliberately-separate gate: false for every tenant account,
// true only for an actual platform operator, and never set by any
// user-creation code path.
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_platform_admin')->default(false)->after('role');
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('is_platform_admin');
        });
    }
};
