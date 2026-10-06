<?php
namespace Database\Seeders;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The four-tier model: trial (7 days, full pro access) -> free (permanent,
 * hard-capped) -> pro (small shop/corporate site) -> business (large shop/
 * company). See the 2026-09-30 business-model migration for how these same
 * fixed ids carried the old free/starter/growth/enterprise rows before this.
 */
class PlanSeeder extends Seeder {
    public function run(): void {
        $freeTools = ['search_products', 'get_product_availability'];
        $proTools = array_merge($freeTools, [
            'get_product_variants', 'compare_products', 'recommend_products',
            'build_cart_url', 'add_to_cart', 'create_payment_link',
        ]);
        $businessTools = array_merge($proTools, ['get_order_status']);

        // price_yearly = price_monthly * (12 - months the discount is worth)
        // — an editable setting, not a hardcoded multiplier, so "two months
        // free" can become "one month free" from the panel without a code
        // change. Falls back to 2 (-> the historical *10) if Settings isn't
        // reachable yet (this seeder can run before the settings table does
        // on a genuinely fresh install) or hasn't been configured.
        try {
            $monthsFree = (float) Settings::get('pricing.annual_discount_months');
        } catch (\Throwable $e) {
            $monthsFree = 2.0;
        }
        $yearlyMultiplier = max(0, 12 - $monthsFree);

        $plans = [
            [
                'id' => '00000000-0000-0000-0000-000000000005', 'name' => 'آزمایشی', 'name_en' => 'Trial', 'slug' => 'trial',
                'price_monthly' => 0, 'max_chatbots' => 3, 'max_tokens_monthly' => 500000, 'max_documents' => 2000,
                'max_messages_monthly' => 100000, 'max_domains' => 3, 'model_tier' => 'gemini-1.5-flash',
                'allowed_tools' => $proTools, 'can_purchase_tokens' => false, 'branding_removable' => true,
                'quota_exceeded_behavior' => 'stop', 'is_public' => false, 'sort_order' => 0,
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000001', 'name' => 'رایگان', 'name_en' => 'Free', 'slug' => 'free',
                'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 150000, 'max_documents' => 100,
                'max_messages_monthly' => 100, 'max_domains' => 1, 'model_tier' => 'gemini-1.5-flash-8b',
                'allowed_tools' => $freeTools, 'can_purchase_tokens' => false, 'branding_removable' => false,
                'quota_exceeded_behavior' => 'stop', 'is_public' => false, 'sort_order' => 1,
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000002', 'name' => 'پرو', 'name_en' => 'Pro', 'slug' => 'pro',
                'price_monthly' => 990000, 'max_chatbots' => 3, 'max_tokens_monthly' => 3000000, 'max_documents' => 2000,
                'max_messages_monthly' => 100000, 'max_domains' => 3, 'model_tier' => 'gemini-1.5-flash',
                'allowed_tools' => $proTools, 'can_purchase_tokens' => true, 'branding_removable' => true,
                'quota_exceeded_behavior' => 'auto_wallet', 'is_public' => true, 'sort_order' => 2,
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000003', 'name' => 'بیزینس', 'name_en' => 'Business', 'slug' => 'business',
                'price_monthly' => 2900000, 'max_chatbots' => 10, 'max_tokens_monthly' => 12000000, 'max_documents' => 10000,
                'max_messages_monthly' => 500000, 'max_domains' => 10, 'model_tier' => 'gemini-1.5-flash',
                'allowed_tools' => $businessTools, 'can_purchase_tokens' => true, 'branding_removable' => true,
                'quota_exceeded_behavior' => 'auto_wallet', 'is_public' => true, 'sort_order' => 3,
            ],
        ];

        // insertOrIgnore, not updateOrCreate/firstOrCreate: writes these
        // defaults only the first time each id doesn't exist yet, so an
        // admin's price/limit edit is never reverted by a later deploy —
        // see the 2026-09-29 PlanSeeder fix for why updateOrCreate and
        // Eloquent firstOrCreate() both fail this in different ways (the
        // second silently drops 'id' on the create path, since it is
        // deliberately absent from Plan::$fillable).
        $now = now();
        foreach ($plans as $p) {
            DB::table('plans')->insertOrIgnore([
                'id'                       => $p['id'],
                'name'                     => $p['name'],
                'name_en'                  => $p['name_en'],
                'slug'                     => $p['slug'],
                'price_monthly'            => $p['price_monthly'],
                'price_yearly'             => $p['price_monthly'] * $yearlyMultiplier,
                'max_chatbots'             => $p['max_chatbots'],
                'max_tokens_monthly'       => $p['max_tokens_monthly'],
                'max_documents'            => $p['max_documents'],
                'max_messages_monthly'     => $p['max_messages_monthly'],
                'max_domains'              => $p['max_domains'],
                'model_tier'               => $p['model_tier'],
                'allowed_tools'            => json_encode($p['allowed_tools']),
                'can_purchase_tokens'      => $p['can_purchase_tokens'],
                'branding_removable'       => $p['branding_removable'],
                'quota_exceeded_behavior'  => $p['quota_exceeded_behavior'],
                'features'                 => json_encode([]),
                'sort_order'               => $p['sort_order'],
                'is_active'                => true,
                'is_public'                => $p['is_public'],
                'created_at'               => $now,
                'updated_at'               => $now,
            ]);
        }

        // Retired legacy row (old 'enterprise') — created only on a fresh
        // install where nothing has touched it yet; already-deployed
        // installations get this from the restructure migration instead,
        // which runs once against the row that already existed there.
        DB::table('plans')->insertOrIgnore([
            'id' => '00000000-0000-0000-0000-000000000004',
            'name' => 'Enterprise (legacy)', 'slug' => 'enterprise-legacy',
            'price_monthly' => 0, 'price_yearly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 100000, 'max_documents' => 50,
            'max_messages_monthly' => 1000, 'max_domains' => 1,
            'allowed_tools' => json_encode([]), 'features' => json_encode([]),
            'is_active' => false, 'is_public' => false, 'sort_order' => 9,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
