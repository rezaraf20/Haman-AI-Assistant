<?php
namespace Tests\Feature;

use App\Models\{Plan, Tenant};
use App\Services\TenantService;
use App\Support\AnswerOutcome;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * is_unanswered was a retrieval-score flag only, so it read false on every
 * one of Shop Bot's 119 replies while 26 had failed (13 empty, 5 fallback,
 * 5 "we don't have that", 3 "I can't"). Now every reply is judged
 * (App\Support\AnswerOutcome) and filed where it belongs: our failures in
 * bot_failures, "we don't have that" in the shop's demand-gap report.
 */
class AnswerOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private function makeChatbot(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Shop', 'email' => Str::random(12) . '@example.test',
            'plan_id' => $plan->id, 'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Shop Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'welcome_message' => 'hi', 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => $conversationId, 'chatbot_id' => $chatbotId, 'session_id' => 'sess-1',
            'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');
        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Shop Bot', 'primary_domain' => 'example.test',
        ]);

        return compact('tenant', 'schema', 'chatbotId', 'conversationId');
    }

    private function reply(array $ctx, string $question, array $ai): void
    {
        Http::fake(['*/ai/chat/complete' => Http::response(array_merge([
            'chunk_ids' => [], 'scores' => [], 'sources' => [], 'prompt_tokens' => 0, 'completion_tokens' => 0,
            'total_tokens' => 0, 'cost_toman' => 0, 'model' => 'test/model', 'latency_ms' => 5,
            'is_fallback' => false, 'is_unanswered' => false, 'finish_reason' => 'stop',
        ], $ai), 200)]);

        $this->postJson('/api/v1/chat/message', [
            'chatbot_id' => $ctx['chatbotId'], 'conversation_id' => $ctx['conversationId'],
            'message' => $question, 'session_id' => 'sess-1',
        ], ['Origin' => 'https://example.test'])->assertOk();
    }

    private function inSchema(string $schema, callable $fn): mixed
    {
        DB::statement("SET search_path TO {$schema}, public");
        try { return $fn(); } finally { DB::statement('SET search_path TO public'); }
    }

    private function lastReplyFlag(array $ctx): bool
    {
        return (bool) $this->inSchema($ctx['schema'], fn () => DB::table('messages')->where('role', 'assistant')->latest('created_at')->value('is_unanswered'));
    }

    private function gapEvents(array $ctx): array
    {
        return $this->inSchema($ctx['schema'], fn () => DB::table('conversation_events')->where('event_type', 'unanswered')->pluck('payload')
            ->map(fn ($p) => json_decode($p, true))->all());
    }

    // ── The classifier, on Shop Bot's real replies ────────────────────

    public function test_real_shop_bot_replies_are_classified_and_the_false_positives_are_not(): void
    {
        $cases = [
            ['Sorry, I could not generate a response.', true, 'fallback'],
            ['من نمی‌توانم پاسخی را با محتوای ناسازگار با سیاست‌های شما ایجاد کنم.', false, 'misunderstood'],
            // A leaked "assistant:" prefix is a formatting defect, not a non-answer.
            ['assistant: ببخشید. من یک سیستم عامل کامپیوتری هستم', false, null],
            ['من به صورت مستقیم به سبد خرید شما دسترسی ندارم و نمی‌توانم محصولی را به آن اضافه کنم.', false, 'misunderstood'],
            ['متأسفانه فلاسک ۸۰۰ میلی‌لیتری در فهرست محصولات ما موجود نیست.', false, 'not_carried'],
            ['متأسفانه در این زمینه اطلاعاتی در دسترس نداریم.', false, 'not_carried'],
            ['سلام! متأسفانه در اطلاعات موجود، آدرس اینستاگرام ما ذکر نشده است.', false, 'not_carried'],
            ['', false, 'empty'],
            // Real answers the first, broader pattern wrongly counted as failures:
            ['ماگ دست و پا دار که می‌خواهید بخرید، در فروشگاه ما موجود است.', false, null],
            ['بله، ما موجوداتی را داریم که می‌توانید مشاهده کنید.', false, null],
        ];
        foreach ($cases as [$text, $fallback, $kind]) {
            $this->assertSame($kind, AnswerOutcome::classify(['response' => $text, 'is_fallback' => $fallback])['kind'], $text);
        }
        // Policy replies are not failures.
        $this->assertNull(AnswerOutcome::classify(['response' => 'quota', 'is_fallback' => true, 'finish_reason' => 'quota_exceeded'])['kind']);
    }

    /**
     * Every reply the first production run flagged, judged by hand. Checked
     * against the whole text, half were real answers that mention one
     * missing detail further in; the opening (first two sentences) is what
     * says whether the reply answered.
     */
    public function test_shop_bots_flagged_replies_are_judged_by_their_opening(): void
    {
        $cases = [
 ['ماگ دست و پا دار که می‌خواهید بخرید، در فروشگاه ما موجود است. ماگ دست و پا دار ما از کیفیت بالایی برخوردار است و دارای طراحی جذابی است. این ماگ دست و پا دار به رنگ‌های مختلفی موجود است، اما به دلیل اینکه هیچ رنگی نمی‌توانم', null],
 ['من نمی‌توانم پاسخی را با محتوای ناسازگار با سیاست‌های شما در مورد عدم ارائه اطلاعات شخصی و تماس تلفنی، ایجاد کنم.', 'misunderstood'],
 ['assistant: ببخشید. من یک سیستم عامل کامپیوتری هستم که برای پاسخگویی به سوالات شما طراحی شده است. من نمی‌توانم اطلاعات شخصی یا تماس تلفنی را داشته باشم.', null],
 ['assistant: خب، عذرخواهی شما پذیرفته شده است. اگر سوالی دارید، لطفاً بپرسید تا من پاسخی را برایتان بدهم.', null],
 ['assistant: بله. اگر می‌خواهید بسته لوازم تحریر خرسی را بخرید، ما بسته‌ای را دارا هستیم که شامل دفترچه A6 خرس مهربون - 24000 IRT - [مشاهده محصول](https://khonehrangi.ir/product/2173)، پاک کن', null],
 ['assistant: من یک سیستم عامل کامپیوتری هستم که برای پاسخگویی به سوالات شما طراحی شده است. من نامی ندارم و فقط برای پاسخگویی به سوالات شما در دسترس هستم.', null],
 ['assistant: بله. اگر سوالی دارید، لطفاً بپرسید تا من پاسخی را برایتان بدهم.', null],
 ['من به صورت مستقیم به سبد خرید شما دسترسی ندارم و نمی‌توانم محصولی را شخصاً به آن اضافه کنم. برای افزودن فلاسک ۸۰۰ میل به سبد خرید، لطفاً به صفحهٔ محصول آن مراجعه کنید', 'misunderstood'],
 ['سلام، من دوباره به شما پاسخ می‌دهم. ماگ آبی یکی از رنگ‌های زیبای ما نیست. ماگ آبی را در فروشگاه ما نداریم. اگر می‌خواهید یک ماگ سبز رنگ بخرید', 'not_carried'],
 ['سلام، من دوباره به شما پاسخ می‌دهم. ما تراول ماگ خرس کلاه دار را نداریم. اما ما تراول ماگ خرس سبز را داریم که قیمت آن 310000 تومان است.', 'not_carried'],
 ['متاسفم که ماگ دست و پا دار را پیدا نکردم. اما ماگ دست باز را پیدا کردم که می‌تواند یک گزینه جایگزین باشد.', 'not_carried'],
 ['ماگ درب حیوانات در فروشگاه ما موجود است. ماگ درب حیوانات ما از کیفیت بالایی برخوردار است و دارای طراحی جذابی است. ماگ درب حیوانات ما به رنگ‌های مختلفی موجود است، اما ماگ درب گربه را پیدا کردم', null],
 ['خوش آمدید! برای خرید کیف غذا، چند گزینه داریم. اگر می‌خواهید یک کیف غذا با طرح‌های جور انتخاب کنید، می‌توانید از یکی از این گزینه‌ها استفاده کنید: 1. کیف غذا تک شاخ - 150000 IRT - [مشاهده محصول](https://khonehr', null],
 ['بله، ما موجوداتی را داریم که می‌توانید مشاهده کنید. 1. ماگ درب حیوانات - 295000 IRT - [مشاهده محصول](https://khonehrangi.ir/product/1280) 2. چراغ و قلک خرگوش - 195000 IRT', null],
 ['بسته لوازم تحریر خرسی که شما در نظر دارید، شامل دفترچه A6 خرس مهربون - 24000 IRT - [مشاهده محصول](https://khonehrangi.ir/product/2173)، پاک کن و براش خرس مهربون - 15000 IRT - [مشاهده محصول](https://khonehrangi.', null],
 ['سلام 💕. می‌خواهید بسته لوازم تحریر خرسی بخرید. ما بسته لوازم تحریر خرسی را که شامل دفترچه A6 خرس مهربون - 24000 IRT - [مشاهده محصول](https://khonehrangi.ir/product/2173)، پاک کن', null],
 ['بله، تخته شاسی در بسته لوازم تحریر خرسی موجود نیست. لطفاً با فروشگاه تماس بگیرید تا اطلاعات بیشتر و قیمت دقیق تر را دریافت کنید.', 'not_carried'],
 ['سلام! متأسفانه در اطلاعات موجود، آدرس اینستاگرام یا کانال تلگرام ما ذکر نشده است. اگر می‌خواهید با ما در ارتباط باشید', 'not_carried'],
 ['متأسفانه در این زمینه اطلاعاتی در دسترس نداریم.', 'not_carried'],
 ['سلام! در اطلاعات موجود، رنگ سفید برای هیچ یک از محصولات ذکر نشده است. اگر به دنبال رنگ سفید هستید', 'not_carried'],
 ['متأسفانه در مجموعه‌مان در حال حاضر تراول ماگ موجود نیست. اگر تمایل دارید می‌توانید به صفحه فروشگاه مراجعه کنید', 'not_carried'],
 ['متأسفانه در اطلاعاتی که در دسترس داریم، اشاره‌ای به وضعیت به‌روز بودن سایت نشده است.', 'not_carried'],
 ['این جزئیات در زمینه موجود نیست. برای تأیید، لطفاً مستقیماً با ما تماس بگیرید.', 'not_carried'],
 ['ما در حال حاضر محصولی با رنگ سبز در زمینهٔ خود نداریم. اگر این جزئیات خاص مد نظرتان است', 'not_carried'],
 ['متأسفانه فلاسک ۸۰۰ میلی‌لیتری در فهرست محصولات ما موجود نیست.', 'not_carried'],
 ['در جستجوی خودم برای بررسی موجودی محصولات، موردی با عنوان فلاسک پیدا نکردم — ممکن است با نام دیگری ثبت شده باشد. با این حال، محصولات مرتبط زیر را داریم', 'not_carried'],
 ['در بررسی اطلاعات مربوط به شیوه خرید، امکان خرید حضوری ذکر نشده است — ممکن است با نام دیگری ثبت شده باشد.', 'not_carried'],
 ['در اطلاعاتی که من همین الان بررسی کردم، مطلبی درباره نماد پیدا نکردم — ممکن است با نام یا عبارت دیگری ثبت شده باشد.', 'not_carried'],
 ['مشخصات این دو محصول را در ادامه مقایسه کرده‌ایم: فعلاً «فلاسک ۸۰۰ میل کیف دار» را نداریم. اگر شماره‌تان را بگذارید', 'not_carried'],
 ['برای فلاسک ۸۰۰، چند مدل مختلف با حجم ۸۰۰ میلی‌لیتر در بازار وجود دارد. اما در مورد «فلاسم ۶۰۰»، جستجوی من نتیجه‌ای نداشت', 'not_carried'],
 ['بله، فلاسک ۸۰۰ میل موجود است: ۴۵۰ هزار تومان.', null],
];
        // The stationery-bundle replies in full: every item with its price,
        // and one "price isn't in the list" — an answer with a missing detail.
        $cases[] = ['بسته لوازم تحریر خرسی که شما در نظر دارید، شامل دفترچه A6 - 24000 IRT - [مشاهده محصول](https://khonehrangi.ir/product/2173)، تراش فضانورد - قیمت آن در لیست موجود نیست و می‌توانید با فروشگاه تماس بگیرید، پاک کن و براش خرس مهربون - 15000 IRT.', null];
        $cases[] = ['assistant: بله. اگر می‌خواهید بسته لوازم تحریر خرسی را بخرید، ما بسته‌ای را دارا هستیم که شامل دفترچه A6 خرس مهربون - 24000 IRT - [مشاهده محصول](https://khonehrangi.ir/product/2173)، تراش فضانورد - قیمت آن در لیست موجود نیست.', null];
        foreach ($cases as [$text, $want]) {
            $this->assertSame($want, AnswerOutcome::classify(['response' => $text])['kind'], $text);
        }
    }

    // ── Live, through the real chat endpoint ──────────────────────────

    public function test_an_empty_reply_is_unanswered_and_filed_as_our_failure(): void
    {
        $ctx = $this->makeChatbot();
        $this->reply($ctx, 'فلاسک ۸۰۰ را بذار تو سبد', ['response' => '']);

        $this->assertTrue($this->lastReplyFlag($ctx));
        $f = DB::table('bot_failures')->where('chatbot_id', $ctx['chatbotId'])->first();
        $this->assertSame('empty', $f->kind);
        $this->assertSame('فلاسک ۸۰۰ را بذار تو سبد', $f->question);
        $this->assertSame([], $this->gapEvents($ctx), 'our failure is not the shop\'s demand gap');
        // Points at the real stored reply. Message used to report its UUID
        // as an integer after create() ("9"), which is also what the widget
        // received as message_id for its feedback call.
        $stored = $this->inSchema($ctx['schema'], fn () => DB::table('messages')->where('role', 'assistant')->value('id'));
        $this->assertSame($stored, $f->message_id);
        $this->assertTrue(Str::isUuid($f->message_id));
    }

    public function test_a_fallback_is_unanswered_and_filed_as_our_failure(): void
    {
        $ctx = $this->makeChatbot();
        $this->reply($ctx, 'خداحافظ', ['response' => 'Sorry, I could not generate a response.', 'is_fallback' => true, 'finish_reason' => 'error']);

        $this->assertTrue($this->lastReplyFlag($ctx));
        $this->assertSame('fallback', DB::table('bot_failures')->value('kind'));
    }

    public function test_i_cant_is_filed_as_misunderstood_not_as_a_demand_gap(): void
    {
        $ctx = $this->makeChatbot();
        $this->reply($ctx, 'خودت بنداز تو سبد خرید', ['response' => 'من به سبد خرید شما دسترسی ندارم و نمی‌توانم محصولی را اضافه کنم.']);

        $this->assertTrue($this->lastReplyFlag($ctx));
        $this->assertSame('misunderstood', DB::table('bot_failures')->value('kind'));
        $this->assertSame([], $this->gapEvents($ctx));
    }

    public function test_we_dont_have_it_goes_to_the_demand_gap_report_not_to_our_failures(): void
    {
        $ctx = $this->makeChatbot();
        // Retrieval scored fine (the AI service did not flag it) — the text says it.
        $this->reply($ctx, 'تراول ماگ دارید؟', ['response' => 'متأسفانه در حال حاضر تراول ماگ موجود نیست.']);

        $this->assertTrue($this->lastReplyFlag($ctx));
        $this->assertSame(0, DB::table('bot_failures')->count());
        $events = $this->gapEvents($ctx);
        $this->assertCount(1, $events);
        $this->assertSame('تراول ماگ دارید؟', $events[0]['query']);
        $this->assertSame('reply_says_not_carried', $events[0]['reason']);
    }

    public function test_a_gap_the_ai_service_already_logged_is_not_logged_twice(): void
    {
        $ctx = $this->makeChatbot();
        $this->inSchema($ctx['schema'], fn () => DB::table('conversation_events')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $ctx['conversationId'], 'chatbot_id' => $ctx['chatbotId'],
            'event_type' => 'unanswered', 'payload' => json_encode(['query' => 'XYZ-9000?', 'best_score' => null, 'reason' => 'no_candidates']),
            'created_at' => now(),
        ]));
        $this->reply($ctx, 'XYZ-9000?', ['response' => 'Sorry, I do not have information about that.', 'is_fallback' => true, 'is_unanswered' => true, 'finish_reason' => 'fallback']);

        $this->assertTrue($this->lastReplyFlag($ctx));
        $this->assertCount(1, $this->gapEvents($ctx));
        $this->assertSame(0, DB::table('bot_failures')->count(), 'a retrieval gap is the shop\'s, not ours');
    }

    public function test_a_real_answer_is_not_flagged(): void
    {
        $ctx = $this->makeChatbot();
        $this->reply($ctx, 'فلاسک ۸۰۰ دارید؟', ['response' => 'بله، فلاسک ۸۰۰ میل موجود است: ۴۵۰ هزار تومان.', 'chunk_ids' => ['c1'], 'scores' => [0.8]]);

        $this->assertFalse($this->lastReplyFlag($ctx));
        $this->assertSame(0, DB::table('bot_failures')->count());
        $this->assertSame([], $this->gapEvents($ctx));
    }

    // ── History, from the export ──────────────────────────────────────

    public function test_history_is_reclassified_from_the_export_with_a_baseline_and_rerunning_changes_nothing(): void
    {
        $ctx = $this->makeChatbot();
        $conv = $ctx['conversationId'];
        $t = fn (int $s) => now()->subDay()->addSeconds($s)->toIso8601String();
        $messages = [
            ['id' => (string) Str::uuid(), 'conversation_id' => $conv, 'role' => 'user', 'content' => 'فلاسک ۸۰۰ را بذار تو سبد', 'created_at' => $t(1)],
            ['id' => (string) Str::uuid(), 'conversation_id' => $conv, 'role' => 'assistant', 'content' => '', 'is_fallback' => false, 'is_unanswered' => false, 'created_at' => $t(2)],
            ['id' => (string) Str::uuid(), 'conversation_id' => $conv, 'role' => 'user', 'content' => 'تراول ماگ دارید؟', 'created_at' => $t(3)],
            ['id' => (string) Str::uuid(), 'conversation_id' => $conv, 'role' => 'assistant', 'content' => 'متأسفانه تراول ماگ موجود نیست.', 'is_fallback' => false, 'is_unanswered' => false, 'created_at' => $t(4)],
            ['id' => (string) Str::uuid(), 'conversation_id' => $conv, 'role' => 'user', 'content' => 'قیمت ماگ؟', 'created_at' => $t(5)],
            ['id' => (string) Str::uuid(), 'conversation_id' => $conv, 'role' => 'assistant', 'content' => 'ماگ ۲۹۵ هزار تومان است.', 'is_fallback' => false, 'is_unanswered' => false, 'created_at' => $t(6)],
            ['id' => (string) Str::uuid(), 'conversation_id' => $conv, 'role' => 'user', 'content' => 'خداحافظ', 'created_at' => $t(7)],
            ['id' => (string) Str::uuid(), 'conversation_id' => $conv, 'role' => 'assistant', 'content' => 'Sorry, I could not generate a response.', 'is_fallback' => true, 'is_unanswered' => false, 'created_at' => $t(8)],
        ];
        $this->inSchema($ctx['schema'], function () use ($messages, $ctx) {
            foreach ($messages as $m) {
                DB::table('messages')->insert([
                    'id' => $m['id'], 'conversation_id' => $m['conversation_id'], 'chatbot_id' => $ctx['chatbotId'],
                    'role' => $m['role'], 'content' => $m['content'], 'is_fallback' => $m['is_fallback'] ?? false,
                    'is_unanswered' => false, 'created_at' => $m['created_at'],
                ]);
            }
        });
        $path = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($path, json_encode([
            'chatbot' => ['chatbot_id' => $ctx['chatbotId'], 'name' => 'Shop Bot'],
            'conversations' => [['id' => $conv]], 'messages' => $messages, 'conversation_events' => [],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('haman:reclassify-answers', ['json' => $path, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('answer_quality_baselines')->count(), 'dry run writes nothing');

        $this->artisan('haman:reclassify-answers', ['json' => $path])->assertSuccessful();
        $this->artisan('haman:reclassify-answers', ['json' => $path])->assertSuccessful(); // re-run: replaces, no duplicates
        $this->assertSame(1, DB::table('answer_quality_baselines')->count(), 'a re-run replaces its own baseline');

        $flags = $this->inSchema($ctx['schema'], fn () => DB::table('messages')->where('role', 'assistant')->orderBy('created_at')->pluck('is_unanswered')->map(fn ($v) => (bool) $v)->all());
        $this->assertSame([true, true, false, true], $flags);
        $this->assertEqualsCanonicalizing(['empty', 'fallback'], DB::table('bot_failures')->pluck('kind')->all());
        $this->assertSame(['backfill'], DB::table('bot_failures')->distinct()->pluck('source')->all());
        $this->assertCount(1, $this->gapEvents($ctx));

        $b = DB::table('answer_quality_baselines')->latest('measured_at')->first();
        $this->assertSame(4, (int) $b->replies);
        $this->assertSame(3, (int) $b->failed);
        $this->assertEquals(75.0, (float) $b->failed_pct);
        $this->assertSame(['empty' => 1, 'fallback' => 1, 'misunderstood' => 0, 'not_carried' => 1], json_decode($b->by_kind, true));
        unlink($path);
    }

    public function test_the_admin_failures_page_lists_our_failures_only(): void
    {
        $ctx = $this->makeChatbot();
        $this->reply($ctx, 'فلاسک ۸۰۰ را بذار تو سبد', ['response' => '']);

        $admin = \App\Models\User::create([
            'email' => Str::random(10) . '@example.test', 'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Admin', 'role' => 'owner', 'email_verified_at' => now(), 'locale' => 'en',
        ]);
        // Not mass-assignable on purpose (see BrandSettingsTest::admin()).
        $admin->platform_role = 'admin';
        $admin->platform_is_active = true;
        $admin->save();
        $this->actingAs($admin, 'web')->get('/admin/bot-failures')->assertOk()
            ->assertSee(__('bot_failures.kind_empty'))
            ->assertSee('فلاسک ۸۰۰ را بذار تو سبد');
    }
}
