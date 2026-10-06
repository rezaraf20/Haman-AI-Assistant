<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The old three paid tiers (starter/growth/enterprise) never had a single
 * subscriber — every signup path hardcodes Plan::where('slug','free'), so
 * plan_id has only ever pointed at the free plan. Safe to repurpose those
 * three rows directly rather than create new ones and orphan them:
 *   00000000-...-0001 (free)       -> updated to the new free-tier limits
 *   00000000-...-0002 (starter)    -> renamed to pro
 *   00000000-...-0003 (growth)     -> renamed to business
 *   00000000-...-0004 (enterprise) -> retired (unpublished, kept for FK safety)
 * A new 00000000-...-0005 row is the trial plan — full pro-level access,
 * time-boxed by Tenant.trial_ends_at rather than by its own quota.
 *
 * This is a one-time, deliberate restructuring of admin-owned rows (not the
 * PlanSeeder's own create-only insertOrIgnore, which never touches an
 * existing row again) — the business model itself changed, which is a
 * decision only a migration like this one, run once, should make.
 */
return new class extends Migration {
    public function up(): void {
        $proToolsJson = json_encode([
            'search_products', 'get_product_availability', 'get_product_variants',
            'compare_products', 'recommend_products', 'build_cart_url', 'add_to_cart', 'create_payment_link',
        ]);
        $businessToolsJson = json_encode([
            'search_products', 'get_product_availability', 'get_product_variants',
            'compare_products', 'recommend_products', 'build_cart_url', 'add_to_cart', 'create_payment_link',
            'get_order_status',
        ]);
        $freeToolsJson = json_encode(['search_products', 'get_product_availability']);

        DB::table('plans')->where('id', '00000000-0000-0000-0000-000000000001')->update([
            'name' => 'رایگان', 'name_en' => 'Free', 'slug' => 'free',
            'price_monthly' => 0, 'price_yearly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 150000, 'max_documents' => 100,
            'max_messages_monthly' => 100, 'max_domains' => 1,
            'model_tier' => 'gemini-1.5-flash-8b',
            'allowed_tools' => $freeToolsJson, 'can_purchase_tokens' => false,
            'branding_removable' => false, 'quota_exceeded_behavior' => 'stop',
            'is_active' => true, 'is_public' => false, 'sort_order' => 1,
            'updated_at' => now(),
        ]);

        DB::table('plans')->where('id', '00000000-0000-0000-0000-000000000002')->update([
            'name' => 'پرو', 'name_en' => 'Pro', 'slug' => 'pro',
            'price_monthly' => 990000, 'price_yearly' => 9900000,
            'max_chatbots' => 3, 'max_tokens_monthly' => 3000000, 'max_documents' => 2000,
            'max_messages_monthly' => 100000, 'max_domains' => 3,
            'model_tier' => 'gemini-1.5-flash',
            'allowed_tools' => $proToolsJson, 'can_purchase_tokens' => true,
            'branding_removable' => true, 'quota_exceeded_behavior' => 'auto_wallet',
            'is_active' => true, 'is_public' => true, 'sort_order' => 2,
            'updated_at' => now(),
        ]);

        DB::table('plans')->where('id', '00000000-0000-0000-0000-000000000003')->update([
            'name' => 'بیزینس', 'name_en' => 'Business', 'slug' => 'business',
            'price_monthly' => 2900000, 'price_yearly' => 29000000,
            'max_chatbots' => 10, 'max_tokens_monthly' => 12000000, 'max_documents' => 10000,
            'max_messages_monthly' => 500000, 'max_domains' => 10,
            'model_tier' => 'gemini-1.5-flash',
            'allowed_tools' => $businessToolsJson, 'can_purchase_tokens' => true,
            'branding_removable' => true, 'quota_exceeded_behavior' => 'auto_wallet',
            'is_active' => true, 'is_public' => true, 'sort_order' => 3,
            'updated_at' => now(),
        ]);

        // Retired, not deleted: the "nothing references it" assumption in
        // this file's original docblock was WRONG — a copy of real
        // production data showed two live tenants still on this id (plans
        // can be reassigned by hand from the admin panel after signup, a
        // path the earlier signup-flow audit never covered). The real fix
        // is 2026_09_30_000005 remapping those tenants forward to 'business'
        // (this was the top tier, business is its direct equivalent). This
        // allowed_tools fill is the safety net underneath that: if a tenant
        // is ever found still on this id by some path neither migration
        // anticipated, their chatbots stay usable at the business ceiling
        // instead of silently going toolless.
        DB::table('plans')->where('id', '00000000-0000-0000-0000-000000000004')->update([
            'slug' => 'enterprise-legacy', 'is_public' => false,
            'allowed_tools' => $businessToolsJson, 'can_purchase_tokens' => true,
            'branding_removable' => true, 'quota_exceeded_behavior' => 'auto_wallet',
            'updated_at' => now(),
        ]);

        DB::table('plans')->insertOrIgnore([[
            'id' => '00000000-0000-0000-0000-000000000005',
            'name' => 'آزمایشی', 'name_en' => 'Trial', 'slug' => 'trial',
            'price_monthly' => 0, 'price_yearly' => 0,
            // Not a real monthly allowance — the whole 7-day trial's budget,
            // see QuotaService (a trial tenant is downgraded to free long
            // before a monthly reset would ever matter).
            'max_chatbots' => 3, 'max_tokens_monthly' => 500000, 'max_documents' => 2000,
            'max_messages_monthly' => 100000, 'max_domains' => 3,
            'model_tier' => 'gemini-1.5-flash',
            'allowed_tools' => $proToolsJson, 'can_purchase_tokens' => false,
            'branding_removable' => true, 'quota_exceeded_behavior' => 'stop',
            'features' => '[]',
            'is_active' => true, 'is_public' => false, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    public function down(): void {
        DB::table('plans')->where('id', '00000000-0000-0000-0000-000000000001')->update([
            'name' => 'Free', 'name_en' => null, 'slug' => 'free',
            'max_chatbots' => 1, 'max_tokens_monthly' => 50000, 'max_documents' => 20,
            'max_messages_monthly' => 200, 'sort_order' => 0,
        ]);
        DB::table('plans')->where('id', '00000000-0000-0000-0000-000000000002')->update([
            'name' => 'Starter', 'name_en' => null, 'slug' => 'starter',
            'price_monthly' => 29, 'price_yearly' => 290,
            'max_chatbots' => 2, 'max_tokens_monthly' => 500000, 'max_documents' => 200,
            'max_messages_monthly' => 2000, 'sort_order' => 1,
        ]);
        DB::table('plans')->where('id', '00000000-0000-0000-0000-000000000003')->update([
            'name' => 'Growth', 'name_en' => null, 'slug' => 'growth',
            'price_monthly' => 99, 'price_yearly' => 990,
            'max_chatbots' => 5, 'max_tokens_monthly' => 2000000, 'max_documents' => 1000,
            'max_messages_monthly' => 10000, 'sort_order' => 2,
        ]);
        DB::table('plans')->where('id', '00000000-0000-0000-0000-000000000004')->update([
            'slug' => 'enterprise', 'is_public' => true,
        ]);
        DB::table('plans')->where('id', '00000000-0000-0000-0000-000000000005')->delete();
    }
};
