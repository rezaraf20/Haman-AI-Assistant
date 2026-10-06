<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TokenPackageSeeder extends Seeder {
    public function run(): void {
        $packages = [
            ['id' => '00000000-0000-0000-0000-000000000101', 'name' => 'یک میلیون توکن', 'name_en' => '1 Million Tokens', 'token_amount' => 1000000, 'price_toman' => 250000, 'sort_order' => 1],
            ['id' => '00000000-0000-0000-0000-000000000102', 'name' => 'پنج میلیون توکن', 'name_en' => '5 Million Tokens', 'token_amount' => 5000000, 'price_toman' => 1100000, 'sort_order' => 2],
            ['id' => '00000000-0000-0000-0000-000000000103', 'name' => 'بیست میلیون توکن', 'name_en' => '20 Million Tokens', 'token_amount' => 20000000, 'price_toman' => 4000000, 'sort_order' => 3],
        ];

        $now = now();
        foreach ($packages as $p) {
            // insertOrIgnore, matching PlanSeeder's own reasoning exactly:
            // an admin's price/amount edit through BuyTokens' backing
            // resource must never be reverted by a later deploy.
            DB::table('token_packages')->insertOrIgnore([
                'id'           => $p['id'],
                'name'         => $p['name'],
                'name_en'      => $p['name_en'],
                'chatbot_type' => null,
                'token_amount' => $p['token_amount'],
                'price_toman'  => $p['price_toman'],
                'sort_order'   => $p['sort_order'],
                'is_active'    => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }
}
