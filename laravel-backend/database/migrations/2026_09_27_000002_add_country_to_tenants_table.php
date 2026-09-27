<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which currency a tenant sees everywhere in their own portal — see
 * Tenant::currency(). Collected once, at signup (the email/password path;
 * the phone+OTP path only ever accepts an Iranian mobile number, so those
 * tenants are Iran by definition and never need to be asked).
 *
 * Nullable, and null means Iran/Toman — the same behavior every existing
 * tenant already has today, so this needs no backfill.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            if (!Schema::hasColumn('tenants', 'country')) {
                // 10, not 2 — most values are an ISO 3166-1 alpha-2 code, but
                // App\Support\Countries also offers a literal 'OTHER' for a
                // country not in its list, so a real signup is never blocked
                // by an incomplete dropdown.
                $t->string('country', 10)->nullable()->after('phone');
            }
        });
    }

    public function down(): void {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('country'));
    }
};
