<?php
namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

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
     * Every line of lang/{fa,en}/landing.php is editable from the panel,
     * grouped the way the page itself is laid out (one admin tab per
     * section). The only lines NOT here are faq_q1..7/faq_a1..7: the FAQ is
     * a variable-length list (faq() below), and those numbered keys are only
     * its built-in default. LandingContentKeysTest holds LANDING_KEYS, the
     * lang files and SECTIONS to each other, so a key added to landing.php
     * and forgotten here fails a test instead of silently being code-only.
     */
    public const SECTIONS = [
        'header_footer' => [
            'brand', 'nav_features', 'nav_how', 'nav_pricing', 'nav_faq', 'nav_login', 'nav_cta',
            'nav_lang_fa', 'nav_lang_en', 'footer_tagline', 'footer_login', 'footer_rights',
        ],
        'hero' => [
            'tagline', 'hero_title', 'hero_subtitle', 'hero_cta', 'hero_cta_secondary', 'hero_note',
            'hero_demo_customer', 'hero_demo_bot_intro', 'hero_demo_product_name', 'hero_demo_product_price',
            'hero_demo_product_stock', 'hero_demo_add_to_cart', 'image_hero_alt',
        ],
        'about' => [
            'about_eyebrow', 'about_title', 'about_body1', 'about_body2', 'panel_q', 'panel_a',
            'p1', 'p1p', 'p2', 'p2p', 'p3', 'p3p',
        ],
        'features' => [
            'features_eyebrow', 'features_title', 'features_subtitle',
            'feature1_title', 'feature1_body', 'feature2_title', 'feature2_body',
            'feature3_title', 'feature3_body', 'feature4_title', 'feature4_body',
            'feature5_title', 'feature5_body', 'feature6_title', 'feature6_body',
        ],
        'demo' => [
            'demo_title', 'demo_head', 'demo_sub',
            'demo_tab_sales', 'demo_sales_customer', 'demo_sales_bot',
            'demo_tab_compare', 'demo_compare_customer', 'demo_compare_bot',
            'demo_tab_gap', 'demo_gap_note', 'image_demo_alt',
        ],
        'why' => ['why_eyebrow', 'why_title', 'why1', 'why2', 'why3', 'why4', 'image_why_alt'],
        'how_it_works' => [
            'how_eyebrow', 'integrations_title', 'integrations_subtitle', 'integrations_wp', 'integrations_woo',
            'integrations_step1_title', 'integrations_step1_body',
            'integrations_step2_title', 'integrations_step2_body',
            'integrations_step3_title', 'integrations_step3_body',
        ],
        'pricing' => [
            'pricing_eyebrow', 'pricing_title', 'pricing_subtitle', 'pricing_per_month', 'pricing_cta',
            'pricing_empty', 'pricing_coming_soon', 'pricing_popular',
            'pricing_chatbots', 'pricing_tokens', 'pricing_documents', 'pricing_free_line', 'pricing_enterprise_note',
            'tool_compare_title', 'tool_compare_feature', 'tool_compare_yes', 'tool_compare_no',
            'types_title', 'types_subtitle',
        ],
        'signup' => [
            'signup_title', 'signup_subtitle', 'signup_name', 'signup_email', 'signup_country',
            'signup_country_placeholder', 'signup_country_help', 'signup_password', 'signup_password_confirm',
            'signup_submit', 'signup_phone_alternative', 'signup_email_disabled', 'signup_have_account',
        ],
        'faq' => ['faq_title', 'faq_head'],
        'final_cta' => ['final_cta_title', 'final_cta_subtitle', 'final_cta_button', 'cta_plans'],
    ];

    /** SECTIONS, flattened — the one list applyOverrides()/set() accept. */
    public const LANDING_KEYS = [
        'brand', 'nav_features', 'nav_how', 'nav_pricing', 'nav_faq', 'nav_login', 'nav_cta',
        'nav_lang_fa', 'nav_lang_en', 'footer_tagline', 'footer_login', 'footer_rights',
        'tagline', 'hero_title', 'hero_subtitle', 'hero_cta', 'hero_cta_secondary', 'hero_note',
        'hero_demo_customer', 'hero_demo_bot_intro', 'hero_demo_product_name', 'hero_demo_product_price',
        'hero_demo_product_stock', 'hero_demo_add_to_cart', 'image_hero_alt',
        'about_eyebrow', 'about_title', 'about_body1', 'about_body2', 'panel_q', 'panel_a',
        'p1', 'p1p', 'p2', 'p2p', 'p3', 'p3p',
        'features_eyebrow', 'features_title', 'features_subtitle',
        'feature1_title', 'feature1_body', 'feature2_title', 'feature2_body',
        'feature3_title', 'feature3_body', 'feature4_title', 'feature4_body',
        'feature5_title', 'feature5_body', 'feature6_title', 'feature6_body',
        'demo_title', 'demo_head', 'demo_sub',
        'demo_tab_sales', 'demo_sales_customer', 'demo_sales_bot',
        'demo_tab_compare', 'demo_compare_customer', 'demo_compare_bot',
        'demo_tab_gap', 'demo_gap_note', 'image_demo_alt',
        'why_eyebrow', 'why_title', 'why1', 'why2', 'why3', 'why4', 'image_why_alt',
        'how_eyebrow', 'integrations_title', 'integrations_subtitle', 'integrations_wp', 'integrations_woo',
        'integrations_step1_title', 'integrations_step1_body',
        'integrations_step2_title', 'integrations_step2_body',
        'integrations_step3_title', 'integrations_step3_body',
        'pricing_eyebrow', 'pricing_title', 'pricing_subtitle', 'pricing_per_month', 'pricing_cta',
        'pricing_empty', 'pricing_coming_soon', 'pricing_popular',
        'pricing_chatbots', 'pricing_tokens', 'pricing_documents', 'pricing_free_line', 'pricing_enterprise_note',
        'tool_compare_title', 'tool_compare_feature', 'tool_compare_yes', 'tool_compare_no',
        'types_title', 'types_subtitle',
        'signup_title', 'signup_subtitle', 'signup_name', 'signup_email', 'signup_country',
        'signup_country_placeholder', 'signup_country_help', 'signup_password', 'signup_password_confirm',
        'signup_submit', 'signup_phone_alternative', 'signup_email_disabled', 'signup_have_account',
        'faq_title', 'faq_head',
        'final_cta_title', 'final_cta_subtitle', 'final_cta_button', 'cta_plans',
    ];

    /**
     * Headings that may carry the [[...]] emphasis marker (rendered as
     * <span class="hl">). Everywhere else a marker is stripped by plain().
     */
    public const RICH_KEYS = [
        'hero_title', 'about_title', 'features_title', 'demo_head',
        'why_title', 'integrations_title', 'faq_head', 'final_cta_title',
    ];

    /** Menu items, buttons, badges, tab and field labels: one line in a tight spot. */
    public const SHORT_KEYS = [
        'brand', 'nav_features', 'nav_how', 'nav_pricing', 'nav_faq', 'nav_login', 'nav_cta',
        'nav_lang_fa', 'nav_lang_en', 'footer_login',
        'hero_cta', 'hero_cta_secondary', 'hero_demo_product_price', 'hero_demo_product_stock', 'hero_demo_add_to_cart',
        'about_eyebrow', 'features_eyebrow', 'why_eyebrow', 'how_eyebrow', 'pricing_eyebrow',
        'p1', 'p1p', 'p2', 'p2p', 'p3', 'p3p',
        'demo_tab_sales', 'demo_tab_compare', 'demo_tab_gap',
        'integrations_wp', 'integrations_woo',
        'pricing_per_month', 'pricing_cta', 'pricing_popular',
        'tool_compare_feature', 'tool_compare_yes', 'tool_compare_no',
        'signup_name', 'signup_email', 'signup_country', 'signup_password', 'signup_password_confirm', 'signup_submit',
        'final_cta_button', 'cta_plans',
    ];

    public const MAX_SHORT = 40;
    public const MAX_HEADING = 160;
    public const MAX_TEXT = 600;

    public const IMAGE_SLOTS = ['hero', 'demo', 'why'];
    /** On Brand::DISK (served at /brand/); uploads go under IMAGE_DIR/uploads, defaults ship in public/brand/landing. */
    public const IMAGE_DIR = 'landing';
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
    public const IMAGE_MAX_KB = 1024;

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
                // Translator::addLines() writes straight into
                // $translator->loaded[...] via Arr::set() without ever
                // consulting the file loader first. The very first call
                // for a given group+locale therefore creates a loaded
                // entry containing ONLY the override keys just written —
                // Translator::isLoaded() then reports that group+locale as
                // already loaded, so the real lang/landing.php or
                // lang/legal.php lines never get merged in at all for the
                // rest of the request. Every key nobody has overridden
                // yet (e.g. a legal page whose body was customized here
                // but whose title never was) silently prints its own raw
                // translation key instead of the file's default — this is
                // exactly the bug that shipped: legal.terms_title/
                // legal.terms_body on the live /legal/terms page, and
                // legal.about_title/legal.contact_title alongside their
                // admin-overridden (and therefore fine) body text.
                // Forcing the real group to load first, before addLines()
                // overlays the overrides on top, keeps every untouched key
                // reading its file default exactly as LANG_PATH intends.
                $translator->load('*', 'landing', $locale);
                $translator->load('*', 'legal', $locale);
                $translator->addLines($lines, $locale);
            }
        }
    }

    // ── Landing fields ──────────────────────────────────────────────────

    public static function get(string $key, string $locale): string
    {
        $content = self::content();
        $value = $content['landing'][$key][$locale] ?? null;
        return ($value !== null && $value !== '') ? $value : self::fallback($key, $locale);
    }

    /** The lang-file default, read from the file itself — never the translator, which applyOverrides() may already have patched this request. */
    public static function fallback(string $key, string $locale): string
    {
        static $files = [];
        $files[$locale] ??= (array) require lang_path("{$locale}/landing.php");
        return (string) ($files[$locale][$key] ?? "landing.{$key}");
    }

    public static function maxLength(string $key): int
    {
        if (in_array($key, self::SHORT_KEYS, true)) return self::MAX_SHORT;
        if ($key === 'tagline' || preg_match('/(_title|_head|_alt)$/', $key)) return self::MAX_HEADING;
        return self::MAX_TEXT;
    }

    /** ":count", ":chatbots" … — the replacements the view passes in. An override that drops one would print a blank where a number belongs. */
    public static function placeholders(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}_]):([a-z][a-z_]*)/u', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);
        return $names;
    }

    /**
     * Why $value cannot be saved for $key/$locale, or null when it can.
     * Empty is always allowed: it means "back to the default".
     */
    public static function validationError(string $key, string $locale, ?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        $max = self::maxLength($key);
        if (mb_strlen($value) > $max) {
            return __('content.error_too_long', ['max' => $max]);
        }
        if (substr_count($value, '[[') !== substr_count($value, ']]')) {
            return __('content.error_marker');
        }
        $missing = array_diff(self::placeholders(self::fallback($key, $locale)), self::placeholders($value));
        if ($missing) {
            return __('content.error_placeholder', ['names' => implode('، ', array_map(fn ($n) => ":{$n}", $missing))]);
        }
        return null;
    }

    // ── Rendering ───────────────────────────────────────────────────────

    /**
     * For a heading: the text is HTML-escaped FIRST, and only then is the
     * [[...]] marker turned into <span class="hl">…</span> — so nothing an
     * admin types can ever reach the page as markup; the marker is the only
     * formatting there is.
     */
    public static function rich(?string $text): HtmlString
    {
        $escaped = e((string) $text);
        return new HtmlString(preg_replace('/\[\[(.+?)\]\]/su', '<span class="hl">$1</span>', $escaped));
    }

    /** Everywhere a heading's markup cannot go (the <title>, OG/Twitter tags, aria labels) and every non-heading key: the marker is dropped, the words kept. */
    public static function plain(?string $text): string
    {
        return str_replace(['[[', ']]'], '', (string) $text);
    }

    // ── Images ─────────────────────────────────────────────────────────

    public static function defaultImageUrl(string $slot): string
    {
        return '/brand/' . self::IMAGE_DIR . "/{$slot}.webp";
    }

    /** Disk-relative path of an uploaded image (Brand::DISK), or null while the shipped default is in use. */
    public static function imagePath(string $slot): ?string
    {
        $path = self::content()['images'][$slot] ?? null;
        return is_string($path) && $path !== '' ? $path : null;
    }

    public static function imageUrl(string $slot): string
    {
        $path = self::imagePath($slot);
        return $path ? '/brand/' . ltrim($path, '/') : self::defaultImageUrl($slot);
    }

    public static function imageAlt(string $slot, ?string $locale = null): string
    {
        return self::plain(self::get("image_{$slot}_alt", $locale ?? app()->getLocale()));
    }

    /**
     * Points a slot at a newly uploaded file (or back at the default with
     * null) and deletes the upload it replaces. Only files under
     * IMAGE_DIR/uploads are ever deleted — never a shipped default.
     */
    public static function setImage(string $slot, ?string $path): bool
    {
        if (!in_array($slot, self::IMAGE_SLOTS, true)) {
            throw new \InvalidArgumentException("Unknown landing image slot: {$slot}");
        }
        $path = $path !== null && trim($path) !== '' ? ltrim(trim($path), '/') : null;
        $old = self::imagePath($slot);
        if ($path === $old) return false;

        $settings = PlatformSetting::current();
        $content = $settings->values['content'] ?? [];
        if ($path === null) {
            unset($content['images'][$slot]);
            if (empty($content['images'])) unset($content['images']);
        } else {
            $content['images'][$slot] = $path;
        }
        self::persist($settings, $content);

        if ($old && str_starts_with($old, self::IMAGE_DIR . '/uploads/') && !in_array($old, array_filter(array_map(self::imagePath(...), self::IMAGE_SLOTS)), true)) {
            Storage::disk(Brand::DISK)->delete($old);
        }
        return true;
    }

    /** An SVG is served from our own origin, so one carrying script must never be accepted, even from an admin. */
    public static function svgIsUnsafe(string $svg): bool
    {
        return (bool) preg_match('/<\s*script|<\s*foreignObject|\son[a-z]+\s*=|javascript:|<\s*iframe|<\s*embed|<\s*object/i', $svg);
    }

    public static function set(string $key, string $locale, ?string $value): bool
    {
        if (!in_array($key, self::LANDING_KEYS, true)) {
            throw new \InvalidArgumentException("Not an editable landing key: {$key}");
        }
        $value = trim((string) $value);
        if ($error = self::validationError($key, $locale, $value)) {
            throw new \InvalidArgumentException($error);
        }
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

    /** Drops the memoized row — for tests, whose RefreshDatabase rollback never touches this static (same as Settings::forget()). */
    public static function forget(): void
    {
        self::$cache = null;
    }

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
