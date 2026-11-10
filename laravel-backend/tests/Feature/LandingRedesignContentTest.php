<?php
namespace Tests\Feature;

use App\Filament\Pages\SiteContent;
use App\Models\{ChatbotTypePrice, Plan, User};
use App\Support\{Brand, LandingContent, Settings};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Cache, DB, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The 2026-11 landing redesign: every line of lang/landing.php editable from
 * Site content, per language, with the [[...]] heading marker as the only
 * formatting, and three admin-replaceable images that always render
 * something (a shipped default until one is uploaded).
 */
class LandingRedesignContentTest extends TestCase
{
    use RefreshDatabase;

    /** Keys rendered only in a page state the "everything" fixture does not produce — each covered by its own test below. */
    private const OTHER_STATE_KEYS = ['pricing_empty', 'pricing_coming_soon', 'signup_email_disabled'];

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
        Brand::forget();
        LandingContent::forget();
        Cache::flush();
        Settings::set('payments.fx.eur_to_toman', 50000);
    }

    protected function tearDown(): void
    {
        LandingContent::forget();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────

    /** Plans with tools, a free plan, a chatbot price and working mail: every conditional block on the page renders. */
    private function everythingOnThePage(): void
    {
        Plan::create([
            'name' => 'رشد', 'name_en' => 'Growth', 'slug' => 'growth', 'price_monthly' => 990000,
            'max_chatbots' => 5, 'max_tokens_monthly' => 2000000, 'max_documents' => 3000,
            'allowed_tools' => ['search_products'],
            'is_active' => true, 'is_public' => true, 'sort_order' => 2,
        ]);
        Plan::create([   // no tools: the comparison shows its "not included" mark
            'name' => 'پایه', 'name_en' => 'Basic', 'slug' => 'basic', 'price_monthly' => 490000,
            'max_chatbots' => 1, 'max_tokens_monthly' => 500000, 'allowed_tools' => [],
            'is_active' => true, 'is_public' => true, 'sort_order' => 1,
        ]);
        Plan::create([
            'name' => 'رایگان', 'name_en' => 'Free', 'slug' => 'free', 'price_monthly' => 0,
            'max_chatbots' => 1, 'max_tokens_monthly' => 150000, 'max_documents' => 100, 'max_messages_monthly' => 100,
            'is_active' => true, 'is_public' => false, 'sort_order' => 1,
        ]);
        Settings::set('pricing.popular_plan_slug', 'growth');
        ChatbotTypePrice::create(['type' => 'support', 'name' => 'پشتیبانی', 'name_en' => 'Support', 'price_toman' => 500000, 'is_active' => true]);
        Settings::set('mail.host', 'smtp.example.test');
        Settings::set('mail.from_address', 'bot@example.test');
    }

    /** A value that is unique per key+locale, short enough for the tightest limit, and keeps any :placeholder the default has. */
    private function token(string $key, string $locale): string
    {
        $placeholders = LandingContent::placeholders(LandingContent::fallback($key, $locale));
        return trim('QZ' . substr(md5($key . $locale), 0, 10) . ' ' . implode(' ', array_map(fn ($p) => ":{$p}", $placeholders)));
    }

    private function page(string $locale): string
    {
        Cache::flush();
        return $this->get("/?lang={$locale}")->assertOk()->getContent();
    }

    private function admin(): User
    {
        $user = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Admin', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $user->platform_role = 'admin';
        $user->platform_is_active = true;
        $user->save();

        return $user;
    }

    // ── the key list itself ─────────────────────────────────────────────

    public function test_every_landing_line_except_the_numbered_faq_defaults_is_editable(): void
    {
        foreach (LandingContent::LOCALES as $locale) {
            $fileKeys = array_keys(require lang_path("{$locale}/landing.php"));
            $expected = array_values(array_filter($fileKeys, fn ($k) => !preg_match('/^faq_[qa]\d+$/', $k)));
            sort($expected);
            $actual = LandingContent::LANDING_KEYS;
            sort($actual);
            $this->assertSame($expected, $actual, "lang/{$locale}/landing.php and LANDING_KEYS differ");
        }
    }

    public function test_every_key_sits_in_exactly_one_admin_section(): void
    {
        $flat = array_merge(...array_values(LandingContent::SECTIONS));
        $this->assertSame(count($flat), count(array_unique($flat)), 'a key is listed in two sections');
        $a = $flat; sort($a);
        $b = LandingContent::LANDING_KEYS; sort($b);
        $this->assertSame($b, $a);
        foreach (array_keys(LandingContent::SECTIONS) as $section) {
            foreach (LandingContent::LOCALES as $locale) {
                $this->assertNotSame("content.tab_{$section}", __("content.tab_{$section}", [], $locale), "tab label {$section}/{$locale}");
            }
        }
    }

    public function test_every_shipped_default_passes_its_own_validation(): void
    {
        foreach (LandingContent::LANDING_KEYS as $key) {
            foreach (LandingContent::LOCALES as $locale) {
                $this->assertNull(LandingContent::validationError($key, $locale, LandingContent::fallback($key, $locale)), "{$key}/{$locale}");
            }
        }
    }

    // ── overrides reach the page ────────────────────────────────────────

    public function test_an_override_of_every_key_shows_on_the_page_in_both_languages(): void
    {
        $this->everythingOnThePage();
        foreach (LandingContent::LANDING_KEYS as $key) {
            foreach (LandingContent::LOCALES as $locale) {
                $this->assertTrue(LandingContent::set($key, $locale, $this->token($key, $locale)), "{$key}/{$locale}");
            }
        }
        LandingContent::applyOverrides();

        foreach (LandingContent::LOCALES as $locale) {
            $html = $this->page($locale);
            foreach (LandingContent::LANDING_KEYS as $key) {
                if (in_array($key, self::OTHER_STATE_KEYS, true)) continue;
                $marker = 'QZ' . substr(md5($key . $locale), 0, 10);
                $this->assertStringContainsString($marker, $html, "{$key} override missing from the {$locale} page");
                $other = 'QZ' . substr(md5($key . ($locale === 'fa' ? 'en' : 'fa')), 0, 10);
                $this->assertStringNotContainsString($other, $html, "{$key}: the other language's text leaked onto the {$locale} page");
            }
        }
    }

    public function test_the_empty_state_keys_show_when_nothing_is_published(): void
    {
        foreach (self::OTHER_STATE_KEYS as $key) {
            foreach (LandingContent::LOCALES as $locale) {
                LandingContent::set($key, $locale, $this->token($key, $locale));
            }
        }
        LandingContent::applyOverrides();

        foreach (LandingContent::LOCALES as $locale) {
            $html = $this->page($locale);   // no plans, no mail configured
            foreach (['pricing_empty', 'signup_email_disabled'] as $key) {
                $this->assertStringContainsString('QZ' . substr(md5($key . $locale), 0, 10), $html, "{$key}/{$locale}");
            }
        }

        Plan::create(['name' => 'Seed', 'slug' => 'seed', 'price_monthly' => 29, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000,
            'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        foreach (LandingContent::LOCALES as $locale) {
            $this->assertStringContainsString('QZ' . substr(md5('pricing_coming_soon' . $locale), 0, 10), $this->page($locale));
        }
    }

    public function test_emptying_a_field_restores_the_default_and_stores_nothing(): void
    {
        foreach (['nav_how', 'about_title', 'why3', 'cta_plans', 'image_why_alt'] as $key) {
            foreach (LandingContent::LOCALES as $locale) {
                LandingContent::set($key, $locale, 'Something else');
                $this->assertTrue(LandingContent::set($key, $locale, '   '));
                $this->assertSame(LandingContent::fallback($key, $locale), LandingContent::get($key, $locale));
            }
            $stored = DB::table('platform_settings')->value('values');
            $this->assertArrayNotHasKey($key, json_decode((string) $stored, true)['content']['landing'] ?? [], "{$key} left an empty override behind");
        }

        LandingContent::applyOverrides();
        $this->assertStringContainsString(e(LandingContent::fallback('why3', 'fa')), $this->page('fa'));
        $this->assertStringContainsString(e(LandingContent::fallback('why3', 'en')), $this->page('en'));
    }

    // ── validation ──────────────────────────────────────────────────────

    public function test_short_keys_are_capped_and_long_text_is_not(): void
    {
        $this->assertSame(LandingContent::MAX_SHORT, LandingContent::maxLength('nav_cta'));
        $this->assertSame(LandingContent::MAX_SHORT, LandingContent::maxLength('pricing_popular'));
        $this->assertSame(LandingContent::MAX_HEADING, LandingContent::maxLength('hero_title'));
        $this->assertSame(LandingContent::MAX_TEXT, LandingContent::maxLength('about_body1'));

        $this->assertNotNull(LandingContent::validationError('nav_cta', 'fa', str_repeat('ب', LandingContent::MAX_SHORT + 1)));
        $this->assertNull(LandingContent::validationError('nav_cta', 'fa', str_repeat('ب', LandingContent::MAX_SHORT)));
        $this->assertNull(LandingContent::validationError('about_body1', 'en', str_repeat('a', 500)));

        $this->expectException(\InvalidArgumentException::class);
        LandingContent::set('nav_cta', 'en', str_repeat('x', 41));
    }

    public function test_a_placeholder_the_page_fills_in_cannot_be_dropped(): void
    {
        $this->assertNotNull(LandingContent::validationError('pricing_chatbots', 'fa', 'چت‌بات'));
        $this->assertNull(LandingContent::validationError('pricing_chatbots', 'fa', ':count دستیار'));
        $this->assertNotNull(LandingContent::validationError('pricing_free_line', 'en', 'Free: :chatbots and :documents'));
    }

    public function test_an_unbalanced_marker_is_refused(): void
    {
        $this->assertNotNull(LandingContent::validationError('hero_title', 'en', 'A [[broken heading'));
        $this->assertNull(LandingContent::validationError('hero_title', 'en', 'A [[fine]] heading'));
    }

    // ── the [[...]] marker ──────────────────────────────────────────────

    public function test_the_marker_becomes_a_highlight_and_everything_else_stays_text_in_both_languages(): void
    {
        LandingContent::set('hero_title', 'fa', 'دستیار <b>فروشگاه</b> که [[a<b & "c"]] را بلد است');
        LandingContent::set('hero_title', 'en', 'Assistant <script>alert(1)</script> knows [[x < y]]');
        LandingContent::applyOverrides();

        $fa = $this->page('fa');
        $this->assertStringContainsString('دستیار &lt;b&gt;فروشگاه&lt;/b&gt; که <span class="hl">a&lt;b &amp; &quot;c&quot;</span> را بلد است', $fa);
        $this->assertStringNotContainsString('<b>فروشگاه</b>', $fa);
        // In the <title> and meta tags the marker is gone and the words stay.
        $this->assertStringContainsString('<title>هامان AI — دستیار &lt;b&gt;فروشگاه&lt;/b&gt; که a&lt;b &amp; &quot;c&quot; را بلد است</title>', $fa);
        $this->assertStringNotContainsString('[[', $fa);

        $en = $this->page('en');
        $this->assertStringContainsString('Assistant &lt;script&gt;alert(1)&lt;/script&gt; knows <span class="hl">x &lt; y</span>', $en);
        $this->assertStringNotContainsString('<script>alert(1)', $en);
        $this->assertStringContainsString('content="Haman AI — Assistant &lt;script&gt;alert(1)&lt;/script&gt; knows x &lt; y"', $en);
    }

    public function test_the_shipped_headings_render_their_highlight(): void
    {
        $fa = $this->page('fa');
        $this->assertStringContainsString('<span class="hl">کاتالوگ خودتان</span>', $fa);
        $this->assertStringContainsString('<span class="hl">به آن بدهید</span>', $fa);
        $en = $this->page('en');
        $this->assertStringContainsString('<span class="hl">your catalogue</span>', $en);
        $this->assertStringContainsString('<title>Haman AI — A shop assistant that knows your catalogue</title>', $en);
    }

    public function test_a_marker_in_a_non_heading_is_dropped_not_rendered(): void
    {
        LandingContent::set('hero_subtitle', 'en', 'Plain [[words]] <i>here</i>');
        LandingContent::applyOverrides();

        $en = $this->page('en');
        $this->assertStringContainsString('Plain words &lt;i&gt;here&lt;/i&gt;', $en);
        $this->assertStringNotContainsString('<span class="hl">words</span>', $en);
    }

    // ── no raw keys, no placeholders ────────────────────────────────────

    public function test_neither_language_prints_a_raw_translation_key(): void
    {
        $this->everythingOnThePage();
        foreach (LandingContent::LOCALES as $locale) {
            $html = $this->page($locale);
            $this->assertDoesNotMatchRegularExpression('/\b(landing|legal|content|common)\.[a-z][a-z0-9_]*\b/', strip_tags($html), "raw key on the {$locale} page");
            $this->assertDoesNotMatchRegularExpression('/>\s*(landing|legal)\.[a-z0-9_]+\s*</', $html);
            // The design file's placeholder frames never reach the public page.
            foreach (['slot-key', 'landing.hero_image', 'جایگزین شود', 'replace from the admin panel', '[['] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$needle} on the {$locale} page");
            }
        }
    }

    // ── images ──────────────────────────────────────────────────────────

    public function test_with_nothing_uploaded_each_slot_shows_its_shipped_default(): void
    {
        foreach (LandingContent::IMAGE_SLOTS as $slot) {
            $url = LandingContent::imageUrl($slot);
            $this->assertSame("/brand/landing/{$slot}.webp", $url);
            $this->assertFileExists(public_path(ltrim($url, '/')), "{$slot} default image is missing from public/brand/landing");
        }
        foreach (LandingContent::LOCALES as $locale) {
            $html = $this->page($locale);
            foreach (LandingContent::IMAGE_SLOTS as $slot) {
                $this->assertStringContainsString('src="/brand/landing/' . $slot . '.webp" alt="' . e(LandingContent::imageAlt($slot, $locale)) . '"', $html);
            }
        }
    }

    public function test_alt_text_is_per_language_and_editable(): void
    {
        LandingContent::set('image_hero_alt', 'fa', 'تصویر آزمایشی <فارسی>');
        LandingContent::set('image_hero_alt', 'en', 'Test picture');
        LandingContent::applyOverrides();

        $this->assertStringContainsString('alt="تصویر آزمایشی &lt;فارسی&gt;"', $this->page('fa'));
        $this->assertStringContainsString('alt="Test picture"', $this->page('en'));
    }

    public function test_uploading_replacing_and_clearing_an_image_from_the_panel(): void
    {
        Storage::fake(Brand::DISK);
        $this->actingAs($this->admin(), 'web');
        $disk = Storage::disk(Brand::DISK);
        // Fixed bytes, not UploadedFile::fake()->image(): that needs GD, which the shipped image (and so CI) does not have.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $disk->putFileAs('landing/uploads', UploadedFile::fake()->createWithContent('a.png', $png), 'aaaa1111.png');
        $disk->putFileAs('landing/uploads', UploadedFile::fake()->createWithContent('b.png', $png . ' '), 'bbbb2222.jpg');

        $page = new SiteContent();
        $page->mount();
        // FileUpload state is an array keyed by an upload id, even for one file — see BrandSettingsTest.
        $page->data['image_hero'] = [(string) Str::uuid() => 'landing/uploads/aaaa1111.png'];
        $page->save();

        $this->assertSame('/brand/landing/uploads/aaaa1111.png', LandingContent::imageUrl('hero'));
        $this->assertSame('/brand/landing/demo.webp', LandingContent::imageUrl('demo'), 'the other slots are untouched');
        $this->assertStringContainsString('src="/brand/landing/uploads/aaaa1111.png"', $this->page('fa'));
        $this->assertStringContainsString('src="/brand/landing/uploads/aaaa1111.png"', $this->page('en'), 'one image for both languages');
        $row = DB::table('platform_activity_log')->where('action', 'site_content_changed')->latest('created_at')->first();
        $this->assertSame('/brand/landing/uploads/aaaa1111.png', json_decode($row->after, true)['image.hero']);

        $page = new SiteContent();
        $page->mount();
        $page->data['image_hero'] = [(string) Str::uuid() => 'landing/uploads/bbbb2222.jpg'];
        $page->save();
        $this->assertSame('/brand/landing/uploads/bbbb2222.jpg', LandingContent::imageUrl('hero'));
        $disk->assertMissing('landing/uploads/aaaa1111.png');

        $page = new SiteContent();
        $page->mount();
        $page->data['image_hero'] = [];
        $page->save();
        $this->assertSame('/brand/landing/hero.webp', LandingContent::imageUrl('hero'));
        $disk->assertMissing('landing/uploads/bbbb2222.jpg');
    }

    public function test_saving_the_page_untouched_changes_nothing(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = new SiteContent();
        $page->mount();
        $page->save();

        $this->assertSame(0, DB::table('platform_activity_log')->where('action', 'site_content_changed')->count());
        $stored = json_decode((string) DB::table('platform_settings')->value('values'), true);
        $this->assertEmpty($stored['content']['landing'] ?? []);
        $this->assertEmpty($stored['content']['images'] ?? []);
    }

    public function test_an_svg_with_script_is_refused(): void
    {
        $this->assertTrue(LandingContent::svgIsUnsafe('<svg><script>alert(1)</script></svg>'));
        $this->assertTrue(LandingContent::svgIsUnsafe('<svg><rect onload="x()"/></svg>'));
        $this->assertTrue(LandingContent::svgIsUnsafe('<svg><a href="javascript:x()"/></svg>'));
        $this->assertFalse(LandingContent::svgIsUnsafe(file_get_contents(public_path('brand/hamanai-mark.svg'))));
    }
}
