<?php
namespace Database\Seeders;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder {
    public function run(): void {
        // features holds display bullets only — see Plan::getDisplayFeaturesAttribute().
        // It used to smuggle a 'woocommerce' capability flag in here instead,
        // which nothing ever actually read (grepped: no code checks
        // $plan->features['woocommerce']) and which foreach-printed as a bare
        // "1" on every paid plan's public pricing card. Left empty here
        // rather than inventing marketing copy this codebase has no basis
        // for — see LandingController's own docblock on not claiming
        // anything the product can't back. An admin adds real bullets
        // through PlanResource's Repeater field once there's something
        // specific to say.
        $plans = [
            ['id' => '00000000-0000-0000-0000-000000000001', 'name'=>'Free',       'slug'=>'free',       'price_monthly'=>0,   'max_chatbots'=>1, 'max_tokens_monthly'=>50000,   'max_documents'=>20,   'max_messages_monthly'=>200,   'features'=>[], 'sort_order'=>0],
            ['id' => '00000000-0000-0000-0000-000000000002', 'name'=>'Starter',    'slug'=>'starter',    'price_monthly'=>29,  'max_chatbots'=>2, 'max_tokens_monthly'=>500000,  'max_documents'=>200,  'max_messages_monthly'=>2000,  'features'=>[], 'sort_order'=>1],
            ['id' => '00000000-0000-0000-0000-000000000003', 'name'=>'Growth',     'slug'=>'growth',     'price_monthly'=>99,  'max_chatbots'=>5, 'max_tokens_monthly'=>2000000, 'max_documents'=>1000, 'max_messages_monthly'=>10000, 'features'=>[], 'sort_order'=>2],
            ['id' => '00000000-0000-0000-0000-000000000004', 'name'=>'Enterprise', 'slug'=>'enterprise', 'price_monthly'=>299, 'max_chatbots'=>20,'max_tokens_monthly'=>10000000,'max_documents'=>5000, 'max_messages_monthly'=>50000, 'features'=>[], 'sort_order'=>3],
        ];

        // updateOrCreate, not delete()-then-insert(): this runs on every
        // single deploy (docker-entrypoint.sh), and delete() on a 'slug'
        // already referenced by a real tenant's plan_id throws a foreign
        // key violation — silently, since the entrypoint swallowed it —
        // meaning the seeder had been failing on every deploy since the
        // first tenant signed up, with nothing surfacing that. Matching on
        // 'id' (not 'slug') keeps every existing tenants.plan_id reference
        // intact even if a slug were ever renamed.
        foreach ($plans as $p) {
            Plan::updateOrCreate(['id' => $p['id']], [
                'name'                 => $p['name'],
                'slug'                 => $p['slug'],
                'price_monthly'        => $p['price_monthly'],
                'price_yearly'         => $p['price_monthly'] * 10,
                'max_chatbots'         => $p['max_chatbots'],
                'max_tokens_monthly'   => $p['max_tokens_monthly'],
                'max_documents'        => $p['max_documents'],
                'max_messages_monthly' => $p['max_messages_monthly'],
                'features'             => $p['features'],
                'sort_order'           => $p['sort_order'],
                'is_active'            => true,
            ]);
        }
    }
}
