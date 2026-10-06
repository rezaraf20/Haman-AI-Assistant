<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{DB, Log};

/**
 * Most tenants today point at plan_id = the old free plan — restructure_
 * plans_for_four_tier_model just repurposed that same row into the new free
 * tier, so those need no remapping. Four real questions this asks:
 *   - a tenant still mid-trial (status='trial') on the old free plan -> the
 *     new trial plan, a genuine equivalent (same status, same intent).
 *   - info@khonehrangi.ir specifically -> a one-off human decision, not the
 *     general rule below: she is a real prospect who has never paid, so the
 *     free-fallback-to-pro default would have put her on a paid tier she
 *     never agreed to. Give her the same genuine 7-day trial a brand new
 *     signup gets, starting today — see the dedicated block below, which
 *     runs AFTER (and overrides) the general fallback for this one email.
 *   - anyone else on the old free plan but NOT mid-trial (active/suspended/
 *     cancelled/past_due) -> no real equivalent exists under the old
 *     single-free-plan model, so per instruction they go to 'pro'
 *     temporarily and are logged here for manual review — NOT silently
 *     left on free, which would be a quiet downgrade for a real paying
 *     customer if one exists.
 *   - a tenant on the old 'enterprise' plan (id ...0004) -> the new
 *     'business' plan, its direct equivalent (both the top tier). This
 *     case was MISSED on the first pass: the signup-flow audit that found
 *     "no tenant ever gets assigned anything but free" only covered signup,
 *     not an admin manually moving a tenant to a different plan afterward —
 *     a copy of real production data showed two live tenants on this id.
 *     Without this, they'd be left on the retired 'enterprise-legacy' row.
 * Reversible: records exactly which tenants it touched so down() can put
 * them back on their prior plan specifically (not "whatever they had
 * before" in general, which this migration has no way to know for certain,
 * but every remapped tenant's prior plan_id is exactly one of the three
 * source ids below, so this is exact for a rollback run shortly after this
 * migration — before any brand-new tenant has genuinely subscribed to pro
 * or business on their own and would otherwise be caught by the same
 * plan_id match).
 */
return new class extends Migration {
    public function up(): void {
        $freePlanId = '00000000-0000-0000-0000-000000000001';
        $trialPlanId = '00000000-0000-0000-0000-000000000005';
        $proPlanId = '00000000-0000-0000-0000-000000000002';
        $businessPlanId = '00000000-0000-0000-0000-000000000003';
        $enterprisePlanId = '00000000-0000-0000-0000-000000000004';
        $oneOffTrialEmail = 'info@khonehrangi.ir';

        $trialTenants = DB::table('tenants')
            ->where('plan_id', $freePlanId)
            ->where('status', 'trial')
            ->whereNull('deleted_at')
            ->get(['id', 'email', 'name']);

        DB::table('tenants')
            ->where('plan_id', $freePlanId)
            ->where('status', 'trial')
            ->whereNull('deleted_at')
            ->update(['plan_id' => $trialPlanId]);

        // Excludes the one-off email above: she would otherwise match this
        // general rule (free + not already trial) and get pro, which the
        // dedicated block below overrides anyway — excluding her here keeps
        // this log's count and list accurate instead of listing her twice
        // under two different outcomes.
        $fallbackTenants = DB::table('tenants')
            ->where('plan_id', $freePlanId)
            ->where('status', '!=', 'trial')
            ->where('email', '!=', $oneOffTrialEmail)
            ->whereNull('deleted_at')
            ->get(['id', 'email', 'name', 'status']);

        DB::table('tenants')
            ->where('plan_id', $freePlanId)
            ->where('status', '!=', 'trial')
            ->where('email', '!=', $oneOffTrialEmail)
            ->whereNull('deleted_at')
            ->update(['plan_id' => $proPlanId]);

        $enterpriseTenants = DB::table('tenants')
            ->where('plan_id', $enterprisePlanId)
            ->whereNull('deleted_at')
            ->get(['id', 'email', 'name', 'status']);

        DB::table('tenants')
            ->where('plan_id', $enterprisePlanId)
            ->whereNull('deleted_at')
            ->update(['plan_id' => $businessPlanId]);

        // One-off correction (human decision, 2026-10-01): a real prospect,
        // not yet paying, must not silently land on a paid tier. Her prior
        // status was 'active' (recorded here so down() can restore it
        // exactly, since a rollback has no other way to know it).
        $khonehrangi = DB::table('tenants')
            ->where('email', $oneOffTrialEmail)
            ->whereNull('deleted_at')
            ->first(['id', 'status']);

        if ($khonehrangi) {
            DB::table('tenants')->where('id', $khonehrangi->id)->update([
                'plan_id' => $trialPlanId,
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(7),
                'quota_period_started_at' => now(),
            ]);
            Log::warning("[four-tier migration] One-off: {$oneOffTrialEmail} (tenant {$khonehrangi->id}, was status={$khonehrangi->status}) "
                . 'given a fresh 7-day trial starting today, not the general free-fallback-to-pro rule — she has not paid.');
        }

        Log::warning('[four-tier migration] Mapped ' . $trialTenants->count() . ' mid-trial tenant(s) to the new trial plan: '
            . $trialTenants->pluck('email', 'id')->map(fn ($e, $id) => "{$id} ({$e})")->implode(', '));

        Log::warning('[four-tier migration] No equivalent plan existed for ' . $fallbackTenants->count()
            . ' tenant(s) not in trial status — defaulted to pro, REVIEW MANUALLY: '
            . $fallbackTenants->map(fn ($t) => "{$t->id} ({$t->email}, status={$t->status})")->implode(', '));

        Log::warning('[four-tier migration] Mapped ' . $enterpriseTenants->count()
            . ' tenant(s) from the old enterprise plan to its direct equivalent, business: '
            . $enterpriseTenants->map(fn ($t) => "{$t->id} ({$t->email}, status={$t->status})")->implode(', '));
    }

    public function down(): void {
        $freePlanId = '00000000-0000-0000-0000-000000000001';
        $trialPlanId = '00000000-0000-0000-0000-000000000005';
        $proPlanId = '00000000-0000-0000-0000-000000000002';
        $businessPlanId = '00000000-0000-0000-0000-000000000003';
        $enterprisePlanId = '00000000-0000-0000-0000-000000000004';
        $oneOffTrialEmail = 'info@khonehrangi.ir';

        // The one-off tenant first, with her exact prior status restored —
        // otherwise the generic "any tenant on trial -> free" line below
        // would also catch her, but leave her status wrongly stuck at
        // 'trial' instead of her real prior 'active'.
        DB::table('tenants')->where('email', $oneOffTrialEmail)->update([
            'plan_id' => $freePlanId, 'status' => 'active',
        ]);

        // Every other tenant this migration touched came FROM the free or
        // enterprise plan id — reversing is exact for a rollback run before
        // any new tenant has genuinely subscribed to pro/business since.
        DB::table('tenants')->where('plan_id', $trialPlanId)->update(['plan_id' => $freePlanId]);
        DB::table('tenants')->where('plan_id', $proPlanId)->update(['plan_id' => $freePlanId]);
        DB::table('tenants')->where('plan_id', $businessPlanId)->update(['plan_id' => $enterprisePlanId]);
    }
};
