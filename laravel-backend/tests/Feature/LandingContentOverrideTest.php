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

    public function test_overriding_one_legal_field_does_not_blank_an_untouched_sibling_field(): void
    {
        // Mirrors production exactly: only the BODY was ever set from the
        // admin panel, the TITLE never was.
        LandingContent::setLegal('about', 'body', 'en', 'Real about body, set from the panel.');

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

        $response = $this->get('/?lang=en');

        $response->assertOk();
        $response->assertSee('Custom hero headline.');
        // hero_subtitle was never overridden -- must still read its default.
        $response->assertSee(__('landing.hero_subtitle', [], 'en'));
        $response->assertDontSee('landing.hero_subtitle', false);
    }
}
