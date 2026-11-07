<?php
namespace Tests\Unit;

use App\Support\PortalTheme;
use Filament\Support\Colors\Color;
use PHPUnit\Framework\TestCase;

/**
 * Guards the customer portal's colour system (App\Support\PortalTheme):
 *  - the pairs the design depends on actually meet WCAG AA, computed with
 *    the WCAG 2.x relative-luminance formula rather than judged by eye;
 *  - every token that stands for a Filament palette shade still equals it,
 *    so Filament's components and our own markup can't drift apart;
 *  - no customer-portal template or the portal stylesheet carries a
 *    literal hex colour — PortalTheme is the only place one may live.
 */
class PortalThemeTest extends TestCase
{
    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $c) {
            $c /= 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, sscanf($hex, '#%02x%02x%02x'));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function t(string $token): string
    {
        return PortalTheme::hex($token);
    }

    /** [foreground token, background token, minimum ratio] */
    public static function pairs(): array
    {
        return [
            // The four combinations the brief names explicitly.
            'white on interactive'        => ['on-interactive', 'interactive', 4.5],
            'white on brand-primary'      => ['on-brand', 'brand-primary', 4.5],
            'text on bg'                  => ['text', 'bg', 4.5],
            'text-muted on surface'       => ['text-muted', 'surface', 4.5],
            // And everything else the stylesheet actually puts together.
            'white on interactive-hover'  => ['on-interactive', 'interactive-hover', 4.5],
            'text-muted on bg'            => ['text-muted', 'bg', 4.5],
            'text on surface-subtle'      => ['text', 'surface-subtle', 4.5],
            'interactive link on surface' => ['interactive', 'surface', 4.5],
            'sidebar label on navy'       => ['on-brand-muted', 'brand-primary', 4.5],
            'danger text on surface'      => ['danger', 'surface', 4.5],
            'success-strong on soft'      => ['success-strong', 'success-soft', 4.5],
            'warning-strong on soft'      => ['warning-strong', 'warning-soft', 4.5],
            'danger-strong on soft'       => ['danger-strong', 'danger-soft', 4.5],
            'info-strong on soft'         => ['info-strong', 'info-soft', 4.5],
            'ai-accent-strong on soft'    => ['ai-accent-strong', 'ai-accent-soft', 4.5],
            // Non-text: meter fills, icons, borders on badges (3:1, WCAG 1.4.11).
            'ai-accent icon on surface'   => ['ai-accent', 'surface', 3.0],
            'success icon on surface'     => ['success', 'surface', 3.0],
        ];
    }

    /** @dataProvider pairs */
    public function test_pair_meets_its_minimum_contrast(string $fg, string $bg, float $min): void
    {
        $ratio = self::contrast(self::t($fg), self::t($bg));
        $this->assertGreaterThanOrEqual($min, $ratio, sprintf('%s (%s) on %s (%s) is %.2f:1, needs %.1f:1', $fg, self::t($fg), $bg, self::t($bg), $ratio, $min));
    }

    /** The reason --interactive exists at all: white on --ai-accent misses AA. Kept as a test so nobody "simplifies" buttons back onto it. */
    public function test_white_on_ai_accent_is_below_aa(): void
    {
        $this->assertLessThan(4.5, self::contrast(self::t('on-interactive'), self::t('ai-accent')));
    }

    public function test_tokens_match_the_filament_palette_shades_they_stand_for(): void
    {
        $palette = PortalTheme::filamentColors();
        $rgb = fn (string $token) => implode(', ', sscanf(self::t($token), '#%02x%02x%02x'));

        $map = [
            'interactive'       => ['primary', 600],
            'interactive-hover' => ['primary', 700],
            'ai-accent'         => ['primary', 500],
            'interactive-soft'  => ['primary', 50],
            'success'           => ['success', 500],
            'success-strong'    => ['success', 600],
            'warning'           => ['warning', 500],
            'warning-strong'    => ['warning', 600],
            'info'              => ['info', 500],
            'info-strong'       => ['info', 600],
            'danger'            => ['danger', 600],
            'bg'                => ['gray', 50],
            'surface-subtle'    => ['gray', 100],
            'border'            => ['gray', 200],
            'border-strong'     => ['gray', 300],
            'text-subtle'       => ['gray', 400],
            'text-muted'        => ['gray', 500],
            'text'              => ['gray', 900],
        ];
        foreach ($map as $token => [$color, $shade]) {
            $this->assertSame($palette[$color][$shade], $rgb($token), "--{$token} should equal Filament {$color}-{$shade}");
        }
        $this->assertSame(Color::Blue[950], $rgb('brand-primary'));
    }

    /** Filament fills buttons with shade 600 and puts white text on it. */
    public function test_every_filled_button_shade_carries_white_text_at_aa(): void
    {
        foreach (PortalTheme::filamentColors() as $name => $palette) {
            if ($name === 'gray') continue;
            $hex = vsprintf('#%02X%02X%02X', array_map('intval', explode(',', $palette[600])));
            $this->assertGreaterThanOrEqual(4.5, self::contrast('#FFFFFF', $hex), "{$name}-600 ({$hex})");
        }
    }

    public function test_no_hardcoded_hex_colours_in_customer_portal_templates(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob("{$root}/resources/views/filament/customer/*/*.blade.php"),
            glob("{$root}/resources/views/filament/customer/*/*/*.blade.php"),
            glob("{$root}/app/Filament/Customer/*/*.php"),
            ["{$root}/public/css/portal-theme.css", "{$root}/app/Providers/Filament/CustomerPanelProvider.php"],
        );
        $this->assertNotEmpty($files);

        $hits = [];
        foreach ($files as $file) {
            foreach (file($file) as $i => $line) {
                if (preg_match('/#[0-9a-fA-F]{3,8}\b(?![\w-])/', $line, $m) && !preg_match('/&#|#\d+\s*$/', $line)) {
                    $hits[] = str_replace($root . '/', '', $file) . ':' . ($i + 1) . ' ' . trim($m[0]);
                }
            }
        }
        $this->assertSame([], $hits, "Hex colour outside App\\Support\\PortalTheme:\n" . implode("\n", $hits));
    }
}
