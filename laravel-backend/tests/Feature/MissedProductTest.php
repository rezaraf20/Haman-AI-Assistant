<?php
namespace Tests\Feature;

use App\Models\{Plan, Tenant, User};
use App\Services\TenantService;
use App\Support\{AnswerOutcomeRecorder, CatalogMatch};
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "How often did we say we don't have it, while the shop did?"
 *
 * On khonehrangi.ir's history, 6 of 17 "we don't have it" replies were about
 * products the shop had in stock (the hooded bear travel mug, the 800 ml
 * flask…). Before such a reply is filed as the shop's demand gap, the
 * question is matched against her own catalogue — with the comparison
 * feature's digit normalisation and fuzzy matching — and an in-stock match
 * is filed as OUR failure instead. The rate is the admin's headline metric.
 */
class MissedProductTest extends TestCase
{
    use RefreshDatabase;

    /** khonehrangi.ir's own products (a sample of the real 173). */
    private const CATALOGUE = [
        ['name' => 'تراول ماگ خرس کلاه دار', 'stock_status' => 'instock'],
        ['name' => 'تراول ماگ دست و پا دار', 'stock_status' => 'instock'],
        ['name' => 'ماگ دست باز', 'stock_status' => 'instock'],
        ['name' => 'تراول ماگ ۶۵۰ میل', 'stock_status' => 'instock'],
        ['name' => 'تراول ماگ عینکی', 'stock_status' => 'outofstock'],
        ['name' => 'فلاسک ۸۰۰ میل', 'stock_status' => 'instock'],
        ['name' => 'فلاسک 600 میل', 'stock_status' => 'instock'],
        ['name' => 'فلاسک کیف دار', 'stock_status' => 'instock'],
        ['name' => 'ماگ شبح', 'stock_status' => 'instock'],
        ['name' => 'تراش دایناسور', 'stock_status' => 'instock'],
        ['name' => 'چشم بند دیو', 'stock_status' => 'outofstock'],
    ];

    public function test_the_fuzzy_ratio_is_pythons_difflib_ratio(): void
    {
        // Computed with Python's difflib.SequenceMatcher(a, b).ratio().
        foreach ([
            ['فلاسک 800 میل', 'فلاسک 800 میل', 1.0],
            ['فلاسم 600', 'فلاسک 600 میل', 0.727273],
            ['تراول ماگ', 'تراول ماگ عینکی', 0.75],
            ['ماگ آبی', 'ماگ شبح', 0.714286],
            ['abcd', 'bcde', 0.75],
            ['تخته شاسی', 'فلاسک کیف دار', 0.272727],
            ['ماگ سبز', 'ماگ دست باز', 0.777778],
        ] as [$a, $b, $python]) {
            $this->assertEqualsWithDelta($python, CatalogMatch::ratio($a, $b), 0.000001, "{$a} / {$b}");
        }
        $this->assertSame(CatalogMatch::normalize('فلاسک 800 میل'), CatalogMatch::normalize('فلاسک ۸۰۰ میل'));
        $this->assertSame('800', CatalogMatch::normalizeDigits('٨٠٠'));
    }

    public function test_khonehrangis_we_dont_have_it_questions_against_its_catalogue(): void
    {
        $cases = [
            // The shop had these in stock.
            ['تراول ماگ خرس کلاه دار هم دارید؟', 'تراول ماگ خرس کلاه دار'],
            ['من فلاسک ۸۰۰ میل رو میخوام', 'فلاسک ۸۰۰ میل'],
            ['فلاسم 800 میل رو با فلاسک کیف دار مقایسه کن', 'فلاسک کیف دار'],
            ['من دنبال تراول ماگ هستم', 'تراول ماگ ۶۵۰ میل'],          // in stock preferred over عینکی (out)
            // Not in the catalogue, or not about a product: no match.
            ['ماگ آبی هم دارید؟', null],                                // "ماگ" alone is not enough
            ['تخته شاسی نداره؟', null],
            ['سفینه فضایی ناسا چند موتور دارد؟', null],
            ['سلام، آدرس اینستا یا کانال تلگرامندارین؟', null],
            ['نماد چرا منقضی شده؟', null],
        ];
        foreach ($cases as [$question, $expected]) {
            $this->assertSame($expected, CatalogMatch::find($question, self::CATALOGUE)['name'] ?? null, $question);
        }
    }

    private function makeShop(array $products): array
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-' . Str::random(6), 'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000, 'is_active' => true, 'sort_order' => 0]);
        $tenant = Tenant::create(['slug' => 't-' . Str::random(6), 'name' => 'Shop', 'email' => Str::random(8) . '@example.test', 'plan_id' => $plan->id, 'schema_name' => 'placeholder', 'status' => 'active']);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);
        $bot = (string) Str::uuid();
        $conv = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert(['id' => $bot, 'name' => 'Shop Bot', 'type' => 'support', 'status' => 'active', 'is_active' => true, 'welcome_message' => 'hi', 'language' => 'fa', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => $conv, 'chatbot_id' => $bot, 'session_id' => 'sess-1', 'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($products as $i => $p) {
            DB::table('products')->insert(['id' => (string) Str::uuid(), 'chatbot_id' => $bot, 'woo_product_id' => 1000 + $i, 'name' => $p['name'], 'stock_status' => $p['stock_status'], 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::statement('SET search_path TO public');
        DB::table('chatbot_index')->insert(['chatbot_id' => $bot, 'tenant_id' => $tenant->id, 'schema_name' => $schema, 'is_active' => true, 'name' => 'Shop Bot', 'primary_domain' => 'example.test']);
        return compact('tenant', 'schema', 'bot', 'conv');
    }

    private function ask(array $shop, string $question, string $reply): void
    {
        Http::fake(['*/ai/chat/complete' => Http::response([
            'response' => $reply, 'chunk_ids' => [], 'scores' => [], 'sources' => [], 'prompt_tokens' => 0, 'completion_tokens' => 0,
            'total_tokens' => 0, 'cost_toman' => 0, 'model' => 'test/model', 'latency_ms' => 5, 'is_fallback' => false,
            'is_unanswered' => false, 'finish_reason' => 'stop',
        ], 200)]);
        $this->postJson('/api/v1/chat/message', ['chatbot_id' => $shop['bot'], 'conversation_id' => $shop['conv'], 'message' => $question, 'session_id' => 'sess-1'],
            ['Origin' => 'https://example.test'])->assertOk();
    }

    private function gapEvents(string $schema): array
    {
        DB::statement("SET search_path TO {$schema}, public");
        try { return DB::table('conversation_events')->where('event_type', 'unanswered')->pluck('payload')->map(fn ($p) => json_decode($p, true))->all(); }
        finally { DB::statement('SET search_path TO public'); }
    }

    public function test_we_dont_have_it_about_an_in_stock_product_is_our_failure_not_her_gap(): void
    {
        $shop = $this->makeShop(self::CATALOGUE);
        $this->ask($shop, 'من فلاسک 800 میل رو میخوام', 'متأسفانه فلاسک ۸۰۰ میلی‌لیتری در فهرست محصولات ما موجود نیست.');

        $row = DB::table('bot_failures')->first();
        $this->assertSame('missed_product', $row->kind);
        $this->assertSame('فلاسک ۸۰۰ میل', $row->matched_product);
        $this->assertSame([], $this->gapEvents($shop['schema']), 'not filed as her demand gap');
    }

    public function test_a_match_that_is_only_out_of_stock_stays_her_restock_signal(): void
    {
        $shop = $this->makeShop([['name' => 'تراول ماگ عینکی', 'stock_status' => 'outofstock']]);
        $this->ask($shop, 'تراول ماگ عینکی دارید؟', 'متأسفانه تراول ماگ عینکی در حال حاضر موجود نیست.');

        $this->assertSame('not_carried', DB::table('bot_failures')->value('kind'));
        $events = $this->gapEvents($shop['schema']);
        $this->assertCount(1, $events);
        $this->assertSame('تراول ماگ عینکی', $events[0]['out_of_stock_product']);
    }

    public function test_a_real_gap_stays_her_gap(): void
    {
        $shop = $this->makeShop(self::CATALOGUE);
        $this->ask($shop, 'ماگ آبی هم دارید؟', 'ماگ آبی را در فروشگاه ما نداریم.');

        $this->assertSame('not_carried', DB::table('bot_failures')->value('kind'));
        $this->assertNull(DB::table('bot_failures')->value('matched_product'));
        $this->assertCount(1, $this->gapEvents($shop['schema']));
    }

    public function test_the_rate_and_the_admin_headline(): void
    {
        $shop = $this->makeShop(self::CATALOGUE);
        $this->ask($shop, 'تراول ماگ خرس کلاه دار هم دارید؟', 'ما تراول ماگ خرس کلاه دار را نداریم.');   // had it
        $this->ask($shop, 'من فلاسک ۸۰۰ میل رو میخوام', 'فلاسک ۸۰۰ میل در فهرست ما موجود نیست.');        // had it
        $this->ask($shop, 'ماگ آبی هم دارید؟', 'ماگ آبی را نداریم.');                                     // real gap

        $rate = AnswerOutcomeRecorder::missedProductRate();
        $this->assertSame(['missed' => 2, 'total' => 3, 'pct' => 66.7], $rate);

        $admin = User::create(['email' => Str::random(8) . '@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'), 'name' => 'A', 'role' => 'owner', 'email_verified_at' => now(), 'locale' => 'en']);
        $admin->platform_role = 'admin';
        $admin->platform_is_active = true;
        $admin->save();
        $this->actingAs($admin, 'web')->get('/admin/bot-failures')->assertOk()
            ->assertSee(__('bot_failures.missed_title'))
            ->assertSee('66.7%')
            ->assertSee('تراول ماگ خرس کلاه دار');
    }
}
