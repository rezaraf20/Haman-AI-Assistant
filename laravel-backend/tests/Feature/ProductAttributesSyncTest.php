<?php
namespace Tests\Feature;

use App\Jobs\EmbedDocumentJob;
use App\Models\{ApiKey, Plan, Tenant, User};
use App\Services\TenantService;
use Illuminate\Support\Facades\{Bus, DB};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WooCommerce attributes, variations, weight and dimensions reach the text
 * the bot searches (plugin 2.8.0+). Before, none of them were sent: a
 * khonehrangi.ir mug offered «قرمز | آبی | صورتی | زرد» and the bot told a
 * customer there was no blue mug.
 */
class ProductAttributesSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): array
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-' . Str::random(6), 'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000, 'is_active' => true, 'sort_order' => 0]);
        $tenant = Tenant::create(['slug' => 't-' . Str::random(6), 'name' => 'Shop', 'email' => Str::random(8) . '@example.test', 'plan_id' => $plan->id, 'schema_name' => 'placeholder', 'status' => 'active']);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);
        $bot = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert(['id' => $bot, 'name' => 'Bot', 'type' => 'support', 'status' => 'active', 'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET search_path TO public');
        DB::table('chatbot_index')->insert(['chatbot_id' => $bot, 'tenant_id' => $tenant->id, 'schema_name' => $schema, 'is_active' => true, 'name' => 'Bot', 'primary_domain' => 'shop.example.test']);
        $raw = 'hfp_' . Str::random(32);
        ApiKey::create(['tenant_id' => $tenant->id, 'chatbot_id' => $bot, 'name' => 'K', 'key_prefix' => substr($raw, 0, 12), 'key_hash' => password_hash($raw, PASSWORD_BCRYPT, ['cost' => 4]), 'scopes' => ['read', 'write', 'sync']]);
        return [$tenant, $schema, $bot, ['Authorization' => "Bearer {$raw}", 'User-Agent' => 'WordPress/7.1.4; https://shop.example.test']];
    }

    private function mug(): array
    {
        return [
            'id' => 1114, 'name' => 'ماگ دسته‌دار', 'price' => '295000', 'currency' => 'IRT', 'stock_status' => 'instock',
            'attributes' => [
                ['name' => '%d8%b1%d9%86%da%af', 'label' => 'رنگ', 'values' => ['قرمز', 'آبی', 'صورتی', 'زرد']],
                ['name' => 'pa_material', 'label' => 'جنس', 'values' => ['سرامیک']],
            ],
            'variations' => [
                ['attributes' => ['سایز' => 'کوچک'], 'price' => '250000', 'stock_status' => 'instock', 'sku' => 'MUG-S'],
                ['attributes' => ['سایز' => 'بزرگ'], 'price' => '320000', 'stock_status' => 'outofstock', 'sku' => 'MUG-L'],
            ],
            'weight' => '0.4 kg',
            'dimensions' => '9 × 9 × 11 cm',
        ];
    }

    private function content(string $schema, int $wooId): string
    {
        DB::statement("SET search_path TO {$schema}, public");
        try { return (string) DB::table('documents')->where('external_id', (string) $wooId)->value('raw_content'); }
        finally { DB::statement('SET search_path TO public'); }
    }

    public function test_attributes_variations_weight_and_dimensions_reach_the_text_the_bot_searches(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        [, $schema, $bot, $h] = $this->makeTenant();

        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $bot, 'products' => [$this->mug()]], $h)->assertStatus(202);

        $text = $this->content($schema, 1114);
        $this->assertStringContainsString('رنگ: قرمز، آبی، صورتی، زرد', $text);
        $this->assertStringContainsString('جنس: سرامیک', $text);
        $this->assertStringContainsString('Variations: سایز: کوچک — 250000 (instock) | سایز: بزرگ — 320000 (outofstock)', $text);
        $this->assertStringContainsString('Weight: 0.4 kg', $text);
        $this->assertStringContainsString('Dimensions: 9 × 9 × 11 cm', $text);

        // Kept on the product row as well (the SKU/attribute lookup reads it).
        DB::statement("SET search_path TO {$schema}, public");
        $stored = json_decode((string) DB::table('products')->where('woo_product_id', 1114)->value('attributes'), true);
        DB::statement('SET search_path TO public');
        $this->assertSame('رنگ', $stored[0]['label']);
    }

    public function test_the_owners_choice_is_enforced_here_too(): void
    {
        Bus::fake([EmbedDocumentJob::class]);
        [, $schema, $bot, $h] = $this->makeTenant();

        // The owner reads colour only.
        $this->putJson("/api/v1/chatbots/{$bot}/sync-settings", [
            'product_attributes' => ['%d8%b1%d9%86%da%af'],
            'available_product_attributes' => [
                '%d8%b1%d9%86%da%af' => ['label' => 'رنگ', 'count' => 5, 'visible' => true, 'samples' => ['قرمز', 'آبی']],
                'pa_material' => ['label' => 'جنس', 'count' => 1, 'visible' => false, 'samples' => ['سرامیک']],
            ],
        ], $h)->assertOk()
            ->assertJsonPath('data.product_attributes', ['%d8%b1%d9%86%da%af'])
            ->assertJsonPath('data.available_product_attributes.pa_material.label', 'جنس');

        // A plugin that sends more anyway does not get it indexed.
        $this->postJson('/api/v1/sync/products', ['chatbot_id' => $bot, 'products' => [$this->mug()]], $h)->assertStatus(202);
        $text = $this->content($schema, 1114);
        $this->assertStringContainsString('رنگ: قرمز، آبی، صورتی، زرد', $text);
        $this->assertStringNotContainsString('سرامیک', $text);
    }

    public function test_until_the_owner_chooses_the_settings_say_so(): void
    {
        [, , $bot, $h] = $this->makeTenant();
        $this->getJson("/api/v1/chatbots/{$bot}/sync-settings", $h)->assertOk()->assertJsonPath('data.product_attributes', null);
    }

    public function test_the_portal_offers_the_reported_attributes_with_samples(): void
    {
        [$tenant, , $bot, $h] = $this->makeTenant();
        $this->putJson("/api/v1/chatbots/{$bot}/sync-settings", [
            'available_product_attributes' => ['%d8%b1%d9%86%da%af' => ['label' => 'رنگ', 'count' => 5, 'visible' => true, 'samples' => ['قرمز', 'آبی']]],
        ], $h)->assertOk();
        $user = User::create(['tenant_id' => $tenant->id, 'email' => Str::random(8) . '@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'), 'name' => 'O', 'role' => 'owner', 'email_verified_at' => now(), 'locale' => 'en']);

        $this->actingAs($user, 'web')->get('/portal/sync-settings/' . $bot)->assertOk()
            ->assertSee(__('portal.sync_product_attributes_title'))
            ->assertSee('قرمز، آبی', false);
    }
}
