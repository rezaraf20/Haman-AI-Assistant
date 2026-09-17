<?php
namespace Tests\Feature;

use App\Mail\VerifyEmail;
use App\Models\{ChatbotTypePrice, Plan, User};
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public site, email signup, and the setup guide.
 *
 * Two properties matter most here and are easy to lose: prices on the public
 * page must come from the plans an admin edits rather than from the markup,
 * and email signup must be visibly unavailable when SMTP is not configured
 * instead of failing after someone fills in the form.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();

        // The signup limiter counts per IP per day and lives in the cache,
        // which RefreshDatabase does not touch — so without this every test
        // after the first few would be answered 429 by the previous ones.
        Cache::flush();
    }

    private function publishedPlan(array $attrs = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Growth', 'slug' => 'growth-' . Str::random(5),
            'price_monthly' => 99, 'max_chatbots' => 5, 'max_tokens_monthly' => 2000000,
            'is_active' => true, 'is_public' => true, 'sort_order' => 2,
        ], $attrs));
    }

    private function configureSmtp(): void
    {
        Settings::set('mail.host', 'smtp.example.test');
        Settings::set('mail.from_address', 'bot@example.test');
    }

    /**
     * Post the signup form the way the browser does.
     *
     * The token is supplied rather than the middleware switched off: the form
     * is on a public page, so CSRF protection is the only thing standing
     * between it and a third-party site posting on a visitor's behalf, and a
     * test that skips the middleware would not notice if the route ever left
     * the web group.
     */
    private function submitSignup(string $email, string $name = 'A')
    {
        // Signup puts the new tenant on the free plan, by slug. It is seeded
        // in every real environment but not created by these tests, and its
        // absence surfaces as a bare 404 from the form post.
        Plan::firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_monthly' => 0, 'max_chatbots' => 1,
            'max_tokens_monthly' => 100000, 'is_active' => true,
            'is_public' => false, 'sort_order' => 0,
        ]);

        return $this->withSession(['_token' => 'test-token'])->post('/signup', [
            '_token' => 'test-token',
            'name' => $name, 'email' => $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
    }

    // ── the page exists and is public ───────────────────────────────────

    public function test_the_landing_page_is_reachable_without_signing_in(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_it_shows_the_price_from_the_plan_not_from_the_markup(): void
    {
        // A name no piece of static copy could contain, so this cannot pass
        // on an empty pricing table.
        $this->publishedPlan(['name' => 'PlanFromDatabase', 'price_monthly' => 99]);

        $this->get('/')->assertOk()->assertSee('PlanFromDatabase')->assertSee('99');

        // Change it the way an admin would, and the page follows.
        Plan::where('name', 'PlanFromDatabase')->update(['price_monthly' => 149]);

        $this->get('/')->assertOk()->assertSee('149')->assertDontSee('>99<', false);
    }

    public function test_only_plans_marked_public_are_advertised(): void
    {
        $this->publishedPlan(['name' => 'ShownPlan']);
        Plan::create([
            'name' => 'HiddenPlan', 'slug' => 'hidden-' . Str::random(5), 'price_monthly' => 5,
            'max_chatbots' => 1, 'max_tokens_monthly' => 1000,
            'is_active' => true, 'is_public' => false, 'sort_order' => 9,
        ]);

        $this->get('/')->assertOk()->assertSee('ShownPlan')->assertDontSee('HiddenPlan');
    }

    public function test_the_price_of_a_chatbot_comes_from_the_table_the_panel_charges_from(): void
    {
        // A plan is the monthly subscription; this is the one-off price per
        // chatbot. Both are shown, and both have to match the panel.
        ChatbotTypePrice::create([
            'type' => 'support', 'name' => 'SupportBotType',
            'price_toman' => 4650000, 'is_active' => true,
        ]);

        $this->get('/')->assertOk()
            ->assertSee('SupportBotType')
            ->assertSee(number_format(4650000));
    }

    public function test_an_inactive_chatbot_type_is_not_advertised(): void
    {
        ChatbotTypePrice::create([
            'type' => 'faq', 'name' => 'RetiredBotType',
            'price_toman' => 999000, 'is_active' => false,
        ]);

        $this->get('/')->assertOk()->assertDontSee('RetiredBotType');
    }

    public function test_editing_a_price_in_the_panel_shows_on_the_page_at_once(): void
    {
        // The acceptance criterion: the page reads the tables the panel
        // writes, with nothing in between that could hold a stale number.
        $plan = $this->publishedPlan(['name' => 'LivePricePlan', 'price_monthly' => 120]);
        $type = ChatbotTypePrice::create([
            'type' => 'sales', 'name' => 'LiveTypePrice',
            'price_toman' => 1000000, 'is_active' => true,
        ]);

        $this->get('/')->assertOk()
            ->assertSee('120')
            ->assertSee(number_format(1000000));

        $plan->update(['price_monthly' => 340]);
        $type->update(['price_toman' => 2500000]);

        $this->get('/')->assertOk()
            ->assertSee('340')
            ->assertSee(number_format(2500000))
            ->assertDontSee('>120<', false);
    }

    public function test_the_page_is_cached_briefly_and_varies_on_language(): void
    {
        $response = $this->get('/');

        // Public and short: the same for every visitor, but a price edit has
        // to appear while the admin is still looking at the panel.
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=60', $response->headers->get('Cache-Control'));
        // One URL serves both languages, so a shared cache must not hand a
        // Persian page to an English reader.
        $this->assertStringContainsString('Accept-Language', (string) $response->headers->get('Vary'));
    }

    public function test_it_tells_a_link_preview_what_the_page_is(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['og:title', 'og:description', 'og:url', 'og:site_name', 'twitter:card'] as $tag) {
            $this->assertStringContainsString($tag, $html, $tag);
        }
        $this->assertStringContainsString('rel="canonical"', $html);
    }

    public function test_the_link_preview_card_is_declared_and_really_exists(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('og:image', $html);
        // summary, not summary_large_image, would crop a 1200x630 card to a
        // square thumbnail.
        $this->assertStringContainsString('summary_large_image', $html);

        // A declared card that 404s is worse than none: the preview shows a
        // broken image rather than falling back to text.
        $png = public_path('og/og-image.png');
        $this->assertFileExists($png, 'og:image is declared but not in the image');

        [$width, $height] = getimagesize($png);
        $this->assertSame(1200, $width);
        $this->assertSame(630, $height);
    }

    public function test_the_card_is_what_the_source_svg_renders_to(): void
    {
        // The PNG is committed because it has to be served, but it is
        // generated. Losing the source would leave an image nobody can edit.
        $svg = base_path('resources/og/og-image.svg');

        $this->assertFileExists($svg);
        $this->assertStringContainsString('1200', file_get_contents($svg));

        // scripts/ is repo tooling and is deliberately not copied into the
        // image, so this half only runs where the checkout is.
        if (!is_dir(base_path('scripts'))) {
            return;
        }

        $this->assertFileExists(base_path('scripts/render-og-image.sh'));
    }

    public function test_og_locale_follows_the_rendered_language(): void
    {
        $this->assertStringContainsString(
            'content="fa_IR"',
            $this->withHeader('Accept-Language', 'fa')->get('/')->getContent(),
        );
        $this->assertStringContainsString(
            'content="en_US"',
            $this->withHeader('Accept-Language', 'en')->get('/')->getContent(),
        );
    }

    // ── robots and sitemap ──────────────────────────────────────────────

    public function test_robots_points_at_the_sitemap_and_keeps_crawlers_out_of_the_panels(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));

        $body = $response->getContent();
        $this->assertStringContainsString('Sitemap: ' . url('/sitemap.xml'), $body);
        foreach (['/admin', '/portal', '/api'] as $path) {
            $this->assertStringContainsString('Disallow: ' . $path, $body, $path);
        }
    }

    public function test_the_sitemap_lists_the_public_page_on_this_host(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('xml', $response->headers->get('Content-Type'));

        $body = $response->getContent();
        $this->assertStringContainsString('<loc>' . url('/') . '</loc>', $body);
        // Nothing behind a login belongs in a sitemap.
        foreach (['/admin', '/portal'] as $path) {
            $this->assertStringNotContainsString($path, $body, $path);
        }
    }

    public function test_both_files_name_whichever_host_serves_them(): void
    {
        // They are routes rather than files in public/ precisely so that the
        // move to the final domain needs no edit.
        $this->assertStringContainsString(
            url('/sitemap.xml'), $this->get('/robots.txt')->getContent()
        );
    }

    public function test_the_currency_comes_from_settings(): void
    {
        $this->publishedPlan();
        Settings::set('pricing.default_currency', 'EUR');

        $this->get('/')->assertOk()->assertSee('EUR');
    }

    public function test_it_makes_no_unsupported_claim(): void
    {
        $this->publishedPlan();
        $html = $this->get('/')->getContent();

        // The visible copy, not the markup. Scanning raw HTML also scans the
        // CSRF token in the signup form, and a random token containing "3x"
        // failed this in CI while passing everywhere else -- a claim the page
        // never made. Claims live in prose, so the prose is what is checked.
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // No numeric performance claims, and no platform we do not support.
        foreach (['Shopify', 'Magento', 'PrestaShop', 'BigCommerce', '3x', '×۳', '٪۳۰', '30%'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "landing page claims: {$forbidden}");
        }
    }

    // ── email signup depends on SMTP ────────────────────────────────────

    public function test_the_signup_form_is_hidden_and_explained_when_smtp_is_missing(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee(__('landing.signup_email_disabled'))
            ->assertDontSee('name="password_confirmation"', false);
    }

    public function test_the_signup_form_appears_once_smtp_is_configured(): void
    {
        $this->configureSmtp();

        $this->get('/')->assertOk()
            ->assertSee('name="password_confirmation"', false)
            ->assertDontSee(__('landing.signup_email_disabled'));
    }

    public function test_signing_up_without_smtp_is_refused_even_by_direct_post(): void
    {
        $this->submitSignup('a@example.test')->assertRedirect();
        $this->assertDatabaseMissing('users', ['email' => 'a@example.test']);
    }

    public function test_a_new_account_starts_unverified_and_is_sent_a_link(): void
    {
        $this->configureSmtp();
        Mail::fake();

        $this->submitSignup('new@example.test', 'New Shop')->assertRedirect('/portal');

        $user = User::where('email', 'new@example.test')->first();

        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at, 'an emailed signup must not be pre-verified');
        Mail::assertSentCount(1);
        Mail::assertSent(
            VerifyEmail::class,
            fn (VerifyEmail $mail) => $mail->hasTo('new@example.test')
                && str_contains($mail->link, '/verify-email/' . $user->id . '/'),
        );
    }

    public function test_the_verification_link_verifies_and_a_tampered_one_does_not(): void
    {
        $this->configureSmtp();
        Mail::fake();

        $this->submitSignup('v@example.test', 'V');

        $user = User::where('email', 'v@example.test')->first();

        // Wrong hash: the link is bound to the address it was issued for.
        $this->get(\Illuminate\Support\Facades\URL::temporarySignedRoute('verify.email', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1('someone-else@example.test'),
        ]))->assertRedirect();
        $this->assertNull($user->fresh()->email_verified_at);

        // Correct hash.
        $this->get(\Illuminate\Support\Facades\URL::temporarySignedRoute('verify.email', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]))->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_an_unsigned_verification_url_is_rejected(): void
    {
        $this->configureSmtp();
        Mail::fake();

        $this->submitSignup('u@example.test', 'U');
        $user = User::where('email', 'u@example.test')->first();

        $this->get("/verify-email/{$user->id}/" . sha1($user->email))->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_signup_stops_accepting_once_the_daily_limit_is_reached(): void
    {
        $this->configureSmtp();
        Mail::fake();
        Settings::set('limits.register_per_ip_per_day', 2);

        $this->submitSignup('one@example.test')->assertRedirect('/portal');
        $this->submitSignup('two@example.test')->assertRedirect('/portal');

        // Signup creates a tenant AND a Postgres schema, so it carries the
        // same daily limiter the API's register does.
        $this->submitSignup('three@example.test')->assertStatus(429);
        $this->assertDatabaseMissing('users', ['email' => 'three@example.test']);
    }

    // ── the abuse audit knows about the new endpoints ────────────────────

    public function test_the_new_public_routes_are_classified(): void
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('haman:abuse-audit', ['--fail-on-gap' => true]);

        $this->assertEquals(0, $exit, \Illuminate\Support\Facades\Artisan::output());
    }

    // ── setup guide ─────────────────────────────────────────────────────

    public function test_the_guide_has_a_label_for_every_step_in_both_languages(): void
    {
        $fa = require lang_path('fa/install_guide.php');
        $en = require lang_path('en/install_guide.php');

        foreach (\App\Filament\Customer\Pages\InstallGuide::stepKeys() as $key) {
            foreach (["step_{$key}_title", "step_{$key}_intro", "step_{$key}_body"] as $entry) {
                $this->assertArrayHasKey($entry, $fa, $entry);
                $this->assertArrayHasKey($entry, $en, $entry);
            }
        }
    }

    public function test_every_troubleshooting_case_is_complete_in_both_languages(): void
    {
        $fa = require lang_path('fa/install_guide.php');
        $en = require lang_path('en/install_guide.php');

        // The ones the blade renders — a missing entry would print the key.
        foreach (['404', 'firewall', 'outdated', 'nowoo', 'secret'] as $case) {
            foreach (["trouble_{$case}_symptom", "trouble_{$case}_cause", "trouble_{$case}_fix"] as $entry) {
                $this->assertArrayHasKey($entry, $fa, $entry);
                $this->assertArrayHasKey($entry, $en, $entry);
            }
        }
    }

    public function test_the_guide_reuses_the_existing_onboarding_signals(): void
    {
        // Rather than inventing a second, disagreeing notion of progress.
        $source = file_get_contents(app_path('Filament/Customer/Pages/InstallGuide.php'));

        $this->assertStringContainsString('CustomerOnboarding::status', $source);
    }

    public function test_the_offered_plugin_zip_is_the_one_we_build(): void
    {
        $served = public_path('downloads/haman-ai-chatbot.zip');
        $canonical = base_path('../wordpress-plugin/haman-ai-chatbot.zip');

        $this->assertFileExists($served, 'public/downloads is not in the image, so the download link 404s');

        if (!is_file($canonical)) {
            $this->markTestSkipped('Plugin tree not present in this image; preflight covers the comparison.');
        }

        $this->assertSame(hash_file('sha256', $canonical), hash_file('sha256', $served));
    }
}
