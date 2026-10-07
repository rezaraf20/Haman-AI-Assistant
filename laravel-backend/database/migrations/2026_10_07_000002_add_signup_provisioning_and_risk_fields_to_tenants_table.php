<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Resource-allocation gate for signup abuse — see TenantService::
 * registerViaEmail()/provisionVerifiedTenant() and
 * PurgeUnverifiedSignupsCommand.
 *
 * provisioned_at: null means registerViaEmail() has created only this
 * tenant row and its user — no schema, no chatbot, no API key, nothing
 * for an abandoned signup to leave behind. Set the moment
 * provisionVerifiedTenant() actually creates all of that, which now only
 * happens after a real email verification click (or immediately for the
 * phone+OTP path, which verifies before registerViaPhone() is ever
 * called). PurgeUnverifiedSignupsCommand hard-deletes whatever is still
 * null past the grace window, which is safe precisely because null here
 * means there is nothing else to clean up.
 *
 * signup_risk: {"flags": {"invalid_user_agent": true, "honeypot_filled":
 * true, "submitted_too_fast": true}, "score": 2} — computed once at
 * registration (see TenantService::computeSignupRisk()) from signals that
 * cost nothing and never refuse a signup on their own; see that method's
 * own docblock for why blocking was deliberately left out. Shown in
 * TenantResource's table so a human decides what, if anything, to do
 * about a flagged signup.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            if (!Schema::hasColumn('tenants', 'provisioned_at')) {
                $t->timestamp('provisioned_at')->nullable()->after('schema_name');
            }
            if (!Schema::hasColumn('tenants', 'signup_risk')) {
                $t->jsonb('signup_risk')->nullable()->after('provisioned_at');
            }
        });

        // Every tenant created before this migration already has its
        // schema (the old, immediate-provisioning behavior) — backfilling
        // provisioned_at for them means PurgeUnverifiedSignupsCommand only
        // ever considers signups made under the new, deferred flow, never
        // an existing real tenant that simply predates this column.
        DB::table('tenants')->whereNull('provisioned_at')->update(['provisioned_at' => DB::raw('created_at')]);
    }
    public function down(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['provisioned_at', 'signup_risk']);
        });
    }
};
