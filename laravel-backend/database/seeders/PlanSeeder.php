<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanSeeder extends Seeder {
    public function run(): void {
        $plans = [
            ['id' => '00000000-0000-0000-0000-000000000001', 'name'=>'Free',       'slug'=>'free',       'price_monthly'=>0,   'max_chatbots'=>1, 'max_tokens_monthly'=>50000,   'max_documents'=>20,   'max_messages_monthly'=>200,   'features'=>['woocommerce'=>false],'sort_order'=>0],
            ['id' => '00000000-0000-0000-0000-000000000002', 'name'=>'Starter',    'slug'=>'starter',    'price_monthly'=>29,  'max_chatbots'=>2, 'max_tokens_monthly'=>500000,  'max_documents'=>200,  'max_messages_monthly'=>2000,  'features'=>['woocommerce'=>true], 'sort_order'=>1],
            ['id' => '00000000-0000-0000-0000-000000000003', 'name'=>'Growth',     'slug'=>'growth',     'price_monthly'=>99,  'max_chatbots'=>5, 'max_tokens_monthly'=>2000000, 'max_documents'=>1000, 'max_messages_monthly'=>10000, 'features'=>['woocommerce'=>true], 'sort_order'=>2],
            ['id' => '00000000-0000-0000-0000-000000000004', 'name'=>'Enterprise', 'slug'=>'enterprise', 'price_monthly'=>299, 'max_chatbots'=>20,'max_tokens_monthly'=>10000000,'max_documents'=>5000, 'max_messages_monthly'=>50000, 'features'=>['woocommerce'=>true], 'sort_order'=>3],
        ];
        foreach ($plans as $p) {

    DB::table('plans')->where('slug', $p['slug'])->delete();

    DB::table('plans')->insert([
        'id' => $p['id'],
        'name' => $p['name'],
        'slug' => $p['slug'],
        'price_monthly' => $p['price_monthly'],
        'max_chatbots' => $p['max_chatbots'],
        'max_tokens_monthly' => $p['max_tokens_monthly'],
        'max_documents' => $p['max_documents'],
        'max_messages_monthly' => $p['max_messages_monthly'],
        'features' => json_encode($p['features']),
        'sort_order' => $p['sort_order'],
        'is_active' => true,
        'price_yearly' => $p['price_monthly'] * 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
    }
}
