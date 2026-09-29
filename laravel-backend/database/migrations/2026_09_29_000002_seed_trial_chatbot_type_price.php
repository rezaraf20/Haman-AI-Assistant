<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Every signup (registerViaEmail/registerViaPhone, TenantService) creates one
 * of these automatically so a new tenant reaches a working widget without
 * first having to buy a chatbot from an empty wallet. price_toman=0 and
 * is_active=false on purpose: this type is never meant to appear in
 * BuyChatbot's self-purchase dropdown (which only lists ChatbotTypePrice::
 * active()) — it exists solely so the row TenantService::createTrialChatbot()
 * points 'type' at is a real, priced, admin-editable catalog entry like every
 * other chatbot type, not a magic string with no corresponding row.
 */
return new class extends Migration {
    public function up(): void {
        if (DB::table('chatbot_type_prices')->where('type', 'trial')->exists()) return;

        DB::table('chatbot_type_prices')->insert([
            'id'          => (string) Str::uuid(),
            'type'        => 'trial',
            'name'        => 'آزمایشی رایگان',
            'name_en'     => 'Free Trial',
            'price_toman' => 0,
            'is_active'   => false,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
    public function down(): void {
        DB::table('chatbot_type_prices')->where('type', 'trial')->delete();
    }
};
