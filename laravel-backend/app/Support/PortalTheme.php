<?php
namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * The customer portal's colour tokens — the ONLY place a hex value for the
 * portal is written down. Everything else reads from here:
 *
 *  - rootCss() is emitted as a :root block on every /portal page (see
 *    CustomerPanelProvider), so public/css/portal-theme.css and every
 *    customer Blade view use var(--token) and never a literal colour.
 *  - filamentColors() is the same palette in the shape Filament's own
 *    ->colors() theme API takes, so Filament's components (buttons, badges,
 *    inputs, notifications — and chart widgets, which read their colours
 *    from those same CSS variables) agree with our own markup on every
 *    shade.
 *    PortalThemeTest fails if a token and the Filament shade it stands for
 *    ever drift apart.
 *
 * The admin panel deliberately does not use any of this — it keeps
 * Brand::primaryColor() and Filament's stock grays, so the two panels stay
 * visibly different.
 *
 * Contrast (WCAG 2.x, checked by PortalThemeTest, not by eye):
 *  - Text on a white or --bg background uses --text or --text-muted, never
 *    --text-subtle (2.56:1 — decoration and disabled states only).
 *  - --text-muted on --surface-subtle is 4.34:1, below AA, so table headers
 *    (the one place --surface-subtle sits behind text) use --text.
 *  - The four semantic brand colours are for fills, icons and borders. As
 *    small text, --success/--warning/--info fail AA (3.74, 2.15, 2.77:1), so
 *    each has a *-strong shade for text and a *-soft tint for backgrounds.
 *  - --ai-accent with white text is 4.47:1, below AA — hence --interactive,
 *    one step darker, for buttons and links; --ai-accent stays on icons,
 *    borders and tints of AI-related elements, with --ai-accent-strong for
 *    any text label on them.
 */
class PortalTheme
{
    public const TOKENS = [
        // Structure: sidebar, large headings, emphasis panels. Never a button fill.
        'brand-primary'      => '#172554',
        'on-brand'           => '#FFFFFF',
        'on-brand-muted'     => '#CBD5E1',

        // Buttons, links, the active menu item, focus.
        'interactive'        => '#4F46E5',
        'interactive-hover'  => '#4338CA',
        'interactive-soft'   => '#EEF2FF',
        'on-interactive'     => '#FFFFFF',

        // AI-related elements: bot replies, token usage, answer quality.
        'ai-accent'          => '#6366F1',
        'ai-accent-strong'   => '#4338CA',
        'ai-accent-soft'     => '#EEF2FF',

        'success'            => '#0D9488',
        'success-strong'     => '#0F766E',
        'success-soft'       => '#F0FDFA',
        'warning'            => '#F59E0B',
        'warning-strong'     => '#B45309',
        'warning-soft'       => '#FFFBEB',
        'danger'             => '#DC2626',
        'danger-strong'      => '#B91C1C',
        'danger-soft'        => '#FEF2F2',
        'info'               => '#0EA5E9',
        'info-strong'        => '#0369A1',
        'info-soft'          => '#F0F9FF',

        'bg'                 => '#F8FAFC',
        'surface'            => '#FFFFFF',
        'surface-subtle'     => '#F1F5F9',
        'border'             => '#E2E8F0',
        'border-strong'      => '#CBD5E1',
        'text'               => '#0F172A',
        'text-muted'         => '#64748B',
        'text-subtle'        => '#94A3B8',
    ];

    public static function hex(string $token): string
    {
        return self::TOKENS[$token];
    }

    private static ?string $stylesheetVersion = null;

    /** Content hash of portal-theme.css, appended as ?v= so a deploy never serves a stale cached copy. */
    public static function stylesheetVersion(): string
    {
        return self::$stylesheetVersion ??= substr((string) @md5_file(public_path('css/portal-theme.css')), 0, 8);
    }

    public static function rootCss(): string
    {
        $vars = '';
        foreach (self::TOKENS as $name => $value) {
            $vars .= "--{$name}:{$value};";
        }
        return ":root{{$vars}}";
    }

    /**
     * Filament reads shade 500 for icons and shade 600 for button fills and
     * badge text. Stock Tailwind teal/amber/sky put the brand colour at a
     * shade whose white-text or tinted-badge contrast fails AA, so those
     * three are re-stepped: 500 is the brand colour (icons, which only need
     * 3:1), 600 is the first shade that passes 4.5:1 for both uses. Indigo,
     * red and slate already line up with the tokens as-is.
     */
    public static function filamentColors(): array
    {
        return [
            'primary' => Color::Indigo,
            'gray'    => Color::Slate,
            'danger'  => Color::Red,
            'success' => self::restep(Color::Teal, brand: 600, strong: 700),
            'warning' => self::restep(Color::Amber, brand: 500, strong: 700),
            'info'    => self::restep(Color::Sky, brand: 500, strong: 700),
        ];
    }

    /**
     * Shade 500 becomes the brand step, 600 the strong step, and 700–950
     * continue darker from there; 50–400 are left as Tailwind ships them.
     */
    private static function restep(array $palette, int $brand, int $strong): array
    {
        $steps = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
        $out = [];
        foreach ([50, 100, 200, 300, 400] as $shade) {
            $out[$shade] = $palette[$shade];
        }
        $out[500] = $palette[$brand];

        $from = array_search($strong, $steps, true);
        foreach ([600, 700, 800, 900, 950] as $i => $shade) {
            $out[$shade] = $palette[$steps[min($from + $i, count($steps) - 1)]];
        }

        return $out;
    }
}
