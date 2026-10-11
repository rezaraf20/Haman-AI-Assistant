<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Support\LandingContent;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * LandingContent::applyOverrides() patches Laravel's own translator
 * (Translator::addLines()) rather than changing how views read content, so
 * an existing override for one key in a group must never hide the lang-file
 * default for every OTHER key in that same group — see applyOverrides()'s
 * own comment on why addLines() alone does exactly that (2026-10-11: shipped
 * on production as /legal/about and /legal/contact showing the literal
 * string "legal.about_title"/"legal.contact_title" as their page heading,
 * and /legal/terms — never touched in the admin panel at all — showing
 * both "legal.terms_title" and "legal.terms_body" raw, because the about/
 * contact BODY overrides alone had already marked the whole 'legal' group
 * "loaded" for the request).
 */
class LandingContentOverrideTest extends TestCase
{
    use RefreshDatabase;

    /**
     * applyOverrides() is wired to run once per request via
     * AppServiceProvider::boot() -- correct in real PHP-FPM, where every
     * request is a fresh process, but a single PHPUnit test method only
     * boots the application ONCE (in setUp(), before the test body runs).
     * Calling set()/setLegal() mid-test therefore leaves the translator
     * exactly as it was at boot -- unpatched, and for 'landing' keys
     * specifically, not through ANY mechanism, since (unlike legalTitle()/
     * legalBody(), which read the content store directly as a fallback)
     * the landing page relies purely on the translator having been
     * patched. Calling applyOverrides() again here is not test ceremony;
     * it is literally what the next real visitor's fresh PHP-FPM request
     * does on its own, and skipping it would make these tests pass
     * without ever exercising the bug they exist to catch -- confirmed
     * the hard way: without this call, the landing-key test below failed
     * (the override never appeared at all, not even stomping its
     * sibling), while the two legal-page tests passed for the wrong
     * reason -- legalTitle()/legalBody()'s content-store fallback masked
     * that addLines() was never even invoked in that version of the test.
     */
    public function test_overriding_one_legal_field_does_not_blank_an_untouched_sibling_field(): void
    {
        // Mirrors production exactly: only the BODY was ever set from the
        // admin panel, the TITLE never was.
        LandingContent::setLegal('about', 'body', 'en', 'Real about body, set from the panel.');
        LandingContent::applyOverrides();

        $response = $this->get('/legal/about?lang=en');

        $response->assertOk();
        $response->assertSee('Real about body, set from the panel.');
        // The untouched title must still read its lang/en/legal.php
        // default, never the literal translation key.
        $response->assertSee(__('legal.about_title', [], 'en'));
        $response->assertDontSee('legal.about_title', false);
    }

    public function test_overriding_one_legal_page_does_not_blank_an_entirely_untouched_page(): void
    {
        // 'about' gets touched; 'terms' -- a different slug, same 'legal'
        // translation group -- never does.
        LandingContent::setLegal('about', 'body', 'en', 'Some about body.');
        LandingContent::applyOverrides();

        $response = $this->get('/legal/terms?lang=en');

        $response->assertOk();
        $response->assertSee(__('legal.terms_title', [], 'en'));
        $response->assertSee(__('legal.terms_body', [], 'en'));
        $response->assertDontSee('legal.terms_title', false);
        $response->assertDontSee('legal.terms_body', false);
    }

    public function test_overriding_a_landing_key_does_not_blank_an_untouched_sibling(): void
    {
        LandingContent::set('hero_title', 'en', 'Custom hero headline.');
        LandingContent::applyOverrides();

        $response = $this->get('/?lang=en');

        $response->assertOk();
        $response->assertSee('Custom hero headline.');
        // hero_subtitle was never overridden -- must still read its default.
        $response->assertSee(__('landing.hero_subtitle', [], 'en'));
        $response->assertDontSee('landing.hero_subtitle', false);
    }
}
