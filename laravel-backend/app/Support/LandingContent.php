<?php
namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Lang;

/**
 * Landing-page and legal-page copy, editable from the admin panel's "Site
 * content" page, falling back to lang/*\/landing.php and lang/*\/legal.php
 * whenever a field has not been touched there.
 *
 * Overrides are applied by rewriting Laravel's OWN translation lines
 * (Translator::addLines(), called from AppServiceProvider::boot()) rather
 * than by changing how the landing view reads them — every existing
 * __('landing.hero_title') call (the page <title>, the OG tags, the hero
 * section itself) already picks up an override with no change to the view.
 * FAQ is the one exception: its item COUNT is variable, and a numbered set
 * of translation keys cannot represent that, so LandingController reads
 * faq() directly instead of going through __().
 *
 * Storage rides on the same singleton row Settings already uses
 * (platform_settings.values, a JSON column) under a 'content' sub-key, not
 * a new table — this is exactly the same "only what differs from the
 * default" shape Settings has, just with a locale dimension added, and a
 * second table would only mean a second place to look. It bypasses the
 * Settings class itself, though: SettingsRegistry validates every key
 * against a fixed, hand-registered list, and a default here is a
 * translation call (Lang::get(), resolved at read time so a future code
 * change to landing.php reaches every installation that never overrode that
 * field) rather than a literal — neither fits SettingsRegistry's shape.
 *
 * "Only if changed in the panel" is enforced by comparing a submitted value
 * to what the field currently EFFECTIVELY reads as (get(), override-or-
 * default) rather than to the raw lang-file default: reading the raw
 * default from Lang::get() mid-request would be unreliable once
 * applyOverrides() has already patched the translator for OTHER already-
 * overridden keys this same request. Comparing against the effective value
 * sidesteps that entirely and is also just the more direct reading of "did
 * this field change" — an edit that happens to retype the original words
 * exactly is indistinguishable from no edit either way.
 */
class LandingContent
{
    public const LOCALES = ['fa', 'en'];

    /**
     * Exactly the four sections asked for: headline/subtitle, features,
     * how-it-works (the three-step "integrations" block), final CTA. The
     * rest of landing.php — nav labels, the live conversation demo tabs,
     * pricing/signup copy, footer boilerplate — is UI chrome, not marketing
     * copy, and stays code-only.
     */
    public const LANDING_KEYS = [
        'hero_title', 'hero_subtitle',
        'features_title', 'features_subtitle',
        'feature1_title', 'feature1_body',
        'feature2_title', 'feature2_body',
        'feature3_title', 'feature3_body',
        'feature4_title', 'feature4_body',
        'feature5_title', 'feature5_body',
        'feature6_title', 'feature6_body',
        'integrations_title', 'integrations_subtitle',
        'integrations_step1_title', 'integrations_step1_body',
        'integrations_step2_title', 'integrations_step2_body',
        'integrations_step3_title', 'integrations_step3_body',
        'final_cta_title', 'final_cta_subtitle', 'final_cta_button',
    ];

    public const LEGAL_PAGES = ['about', 'contact', 'terms', 'privacy'];

    // ── Applying overrides to the translator ───────────────────────────

    /**
     * Called once per request (AppServiceProvider::boot(), before routing —
     * the standard PHP-FPM model reruns this fresh every request, so a
     * change made in the panel is live for the very next request, with
     * nothing to restart).
     */
    public static function applyOverrides(): void
    {
        $content = self::content();
        if (!$content) return;

        $translator = app('translator');

        foreach (self::LOCALES as $locale) {
            $lines = [];

            foreach (self::LANDING_KEYS as $key) {
                $value = $content['landing'][$key][$locale] ?? null;
                if ($value !== null && $value !== '') {
                    $lines["landing.{$key}"] = $value;
                }
            }

            foreach (self::LEGAL_PAGES as $slug) {
                $title = $content['legal'][$slug]['title'][$locale] ?? null;
                $body  = $content['legal'][$slug]['body'][$locale] ?? null;
                if ($title !== null && $title !== '') $lines["legal.{$slug}_title"] = $title;
                if ($body  !== null && $body  !== '') $lines["legal.{$slug}_body"]  = $body;
            }

            if ($lines) {
                $translator->addLines($lines, $locale);
            }
        }
    }

    // ── Landing fields ──────────────────────────────────────────────────

    public static function get(string $key, string $locale): string
    {
        $content = self::content();
        return $content['landing'][$key][$locale] ?? Lang::get("landing.{$key}", [], $locale);
    }

    public static function set(string $key, string $locale, ?string $value): bool
    {
        $value = trim((string) $value);
        if ($value === self::get($key, $locale)) return false;

        $settings = PlatformSetting::current();
        $content = $settings->values['content'] ?? [];

        if ($value === '') {
            unset($content['landing'][$key][$locale]);
            if (empty($content['landing'][$key] ?? null)) unset($content['landing'][$key]);
        } else {
            $content['landing'][$key][$locale] = $value;
        }

        self::persist($settings, $content);
        return true;
    }

    // ── Legal pages ──────────────────────────────────────────────────────

    public static function legalTitle(string $slug, string $locale): string
    {
        $content = self::content();
        return $content['legal'][$slug]['title'][$locale] ?? Lang::get("legal.{$slug}_title", [], $locale);
    }

    public static function legalBody(string $slug, string $locale): string
    {
        $content = self::content();
        return $content['legal'][$slug]['body'][$locale] ?? Lang::get("legal.{$slug}_body", [], $locale);
    }

    public static function setLegal(string $slug, string $field, string $locale, ?string $value): bool
    {
        $current = $field === 'title' ? self::legalTitle($slug, $locale) : self::legalBody($slug, $locale);
        $value = trim((string) $value);
        if ($value === $current) return false;

        $settings = PlatformSetting::current();
        $content = $settings->values['content'] ?? [];

        if ($value === '') {
            unset($content['legal'][$slug][$field][$locale]);
        } else {
            $content['legal'][$slug][$field][$locale] = $value;
        }

        self::persist($settings, $content);
        return true;
    }

    // ── FAQ ────────────────────────────────────────────────────────────

    /** @return array<int, array{q_fa:string,a_fa:string,q_en:string,a_en:string}> */
    public static function faq(): array
    {
        $content = self::content();
        if (!empty($content['faq'])) {
            return $content['faq'];
        }

        return collect(range(1, 7))->map(fn ($i) => [
            'q_fa' => Lang::get("landing.faq_q{$i}", [], 'fa'),
            'a_fa' => Lang::get("landing.faq_a{$i}", [], 'fa'),
            'q_en' => Lang::get("landing.faq_q{$i}", [], 'en'),
            'a_en' => Lang::get("landing.faq_a{$i}", [], 'en'),
        ])->all();
    }

    /** @param array<int, array{q_fa?:string,a_fa?:string,q_en?:string,a_en?:string}> $items */
    public static function setFaq(array $items): void
    {
        $settings = PlatformSetting::current();
        $content = $settings->values['content'] ?? [];

        $normalized = array_values(array_map(fn ($item) => [
            'q_fa' => trim((string) ($item['q_fa'] ?? '')),
            'a_fa' => trim((string) ($item['a_fa'] ?? '')),
            'q_en' => trim((string) ($item['q_en'] ?? '')),
            'a_en' => trim((string) ($item['a_en'] ?? '')),
        ], $items));

        // An empty list is "nothing edited yet", not "no FAQ" — falls back
        // to the built-in seven rather than leaving the section blank.
        $content['faq'] = $normalized ?: null;
        if ($content['faq'] === null) unset($content['faq']);

        self::persist($settings, $content);
    }

    // ── Contact info ───────────────────────────────────────────────────

    /** @return array{address:string,phone:string,email:string} */
    public static function contact(): array
    {
        $content = self::content();
        return [
            'address' => (string) ($content['contact']['address'] ?? ''),
            'phone'   => (string) ($content['contact']['phone'] ?? ''),
            'email'   => (string) ($content['contact']['email'] ?? ''),
        ];
    }

    public static function setContact(array $values): void
    {
        $settings = PlatformSetting::current();
        $content = $settings->values['content'] ?? [];

        $content['contact'] = [
            'address' => trim((string) ($values['address'] ?? '')),
            'phone'   => trim((string) ($values['phone'] ?? '')),
            'email'   => trim((string) ($values['email'] ?? '')),
        ];

        self::persist($settings, $content);
    }

    // ── internals ────────────────────────────────────────────────────────

    private static ?array $cache = null;

    private static function content(): array
    {
        if (self::$cache !== null) return self::$cache;

        try {
            return self::$cache = PlatformSetting::current()->values['content'] ?? [];
        } catch (\Throwable) {
            // Console commands run before the table exists (migrate itself,
            // for one) — same reasoning as Settings::row().
            return self::$cache = [];
        }
    }

    private static function persist(PlatformSetting $settings, array $content): void
    {
        $values = $settings->values ?? [];
        $values['content'] = $content;
        $settings->values = $values;
        $settings->save();

        self::$cache = null;
        Settings::forget();
    }
}
