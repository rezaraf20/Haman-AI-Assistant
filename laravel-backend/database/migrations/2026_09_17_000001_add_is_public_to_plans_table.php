<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Which plans the public pricing table shows.
 *
 * `is_active` already means "a tenant may be on this plan", which is not the
 * same question. The free plan every signup lands on is active but is not
 * necessarily something to advertise, and a bespoke plan agreed with one
 * customer is active and definitely is not. So the landing page asks this
 * column instead, and the choice stays an admin decision rather than a
 * filter hardcoded into a Blade file.
 *
 * Defaults to false: a plan created later does not appear on the public site
 * until someone deliberately publishes it.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('plans', function (Blueprint $t) {
            if (!Schema::hasColumn('plans', 'is_public')) {
                $t->boolean('is_public')->default(false)->after('is_active');
            }
        });

        // The three paid tiers that already exist are the ones worth showing.
        DB::table('plans')->whereIn('slug', ['starter', 'growth', 'enterprise'])->update(['is_public' => true]);

        // Left over from diagnostic runs in earlier sessions: zero tenants on
        // any of them, and they would otherwise sit in the admin plan list
        // forever looking like real products.
        DB::table('plans')
            ->where('slug', 'like', 'diag-%')
            ->orWhere('slug', 'like', 'diag2-%')
            ->orWhere('slug', 'like', 'diag3-%')
            ->whereNotIn('id', function ($q) {
                $q->select('plan_id')->from('tenants')->whereNotNull('plan_id');
            })
            ->delete();
    }

    public function down(): void {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('is_public'));
    }
};
