<?php
namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The platform's own logo/color, editable from the admin Settings page's
 * "برند" (Brand) tab — replacing what used to only change with a code
 * deploy. Read everywhere the brand identity appears: both Filament panels,
 * the landing page (via partials/brand-logo.blade.php, which every one of
 * those already @includes — fixing that one partial covers all three at
 * once), the WordPress widget (via class-haman-public.php's build_config(),
 * an absolute URL since the widget runs on a customer's own domain), and
 * the favicon.
 *
 * Same storage shape as LandingContent/Settings: overrides live in
 * platform_settings.values['brand'] (JSON), under this class's OWN
 * sub-key so it can't collide with either of those. A field nobody has
 * uploaded/set falls back to the platform's existing default — an inline-
 * SVG lockup for the full logo (there was never a separate FILE for it to
 * begin with; see brand-logo.blade.php), the existing resources/brand/
 * files for the mark and its light variant, and config('haman.brand.
 * primary_color') for the color. "Nothing uploaded" must never be
 * indistinguishable from "blank" — see hasCustomLogo()/hasCustomMark().
 *
 * Uploaded files are NOT stored in config/filesystems.php (this app has
 * none tracked in git at all — see check-widget-brand-defaults.php's own
 * comment on why a runtime setting can never live in a cached config file)
 * — the 'brand' disk is registered dynamically, in AppServiceProvider::
 * boot(), rooted at the SAME /shared-assets/brand path nginx already
 * serves at /brand/ with a 7-day cache header (see docker-entrypoint.sh
 * and nginx/conf.d/api.conf) — an upload here is instantly servable, no
 * new nginx config and no separate deploy step. Filenames are content-
 * hashed (uploadedUrl() below), which is what actually busts a stale
 * browser cache: a new upload is a new URL, not the same URL with new
 * bytes behind it, so nothing has to guess how long an old copy might
 * still be cached somewhere.
 */
class Brand
{
    public const DISK = 'brand';
    public const UPLOAD_MAX_KB = 2048;
    public const ALLOWED_MIMES = ['image/svg+xml', 'image/png', 'image/webp'];
    // A mark below this looks blurry as a 32px favicon; a full logo below
    // this looks blurry in a panel header. Not enforced for SVG, which has
    // no intrinsic raster size to check.
    public const MIN_DIMENSION_PX = 64;

    public static function logoUrl(): ?string
    {
        return self::get('logo_url');
    }

    public static function hasCustomLogo(): bool
    {
        return self::logoUrl() !== null;
    }

    public static function markUrl(): string
    {
        return self::get('mark_url') ?? '/brand/hamanai-mark.svg';
    }

    public static function hasCustomMark(): bool
    {
        return self::get('mark_url') !== null;
    }

    public static function markLightUrl(): string
    {
        return self::get('mark_light_url') ?? '/brand/hamanai-mark-light.svg';
    }

    /** For the <link rel="icon" type="..."> tag — the shipped default is always SVG, but an upload can be PNG/WebP too. */
    public static function markMimeType(): string
    {
        return match (strtolower(pathinfo(self::markUrl(), PATHINFO_EXTENSION))) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            default => 'image/svg+xml',
        };
    }

    public static function hasCustomMarkLight(): bool
    {
        return self::get('mark_light_url') !== null;
    }

    public static function primaryColor(): string
    {
        return self::get('primary_color') ?? (string) config('haman.brand.primary_color');
    }

    /** Absolute — for the WordPress widget config, which runs on a customer's own domain. */
    public static function absoluteMarkUrl(): string
    {
        return BrandDomains::landingUrl(self::markUrl());
    }

    public static function absoluteMarkLightUrl(): string
    {
        return BrandDomains::landingUrl(self::markLightUrl());
    }

    /**
     * Stores the file and returns its new public URL. The caller (the
     * Filament page) is responsible for calling set() with the result and
     * for deleting whatever URL it's replacing — this only writes the file.
     */
    public static function storeUpload(UploadedFile $file): string
    {
        $hash = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
        $filename = "{$hash}.{$file->getClientOriginalExtension()}";
        $file->storeAs('uploads', $filename, ['disk' => self::DISK, 'visibility' => 'public']);

        return '/brand/uploads/' . $filename;
    }

    /** Deletes a previously-uploaded file by its stored URL — a no-op for a default (non-uploaded) URL. */
    public static function deleteUpload(?string $url): void
    {
        if (!$url || !str_starts_with($url, '/brand/uploads/')) return;

        $filename = basename($url);
        Storage::disk(self::DISK)->delete('uploads/' . $filename);
    }

    public static function set(string $key, ?string $value): void
    {
        $settings = \App\Models\PlatformSetting::current();
        $values = $settings->values ?? [];
        $brand = $values['brand'] ?? [];

        if ($value === null) {
            unset($brand[$key]);
        } else {
            $brand[$key] = $value;
        }

        $values['brand'] = $brand;
        $settings->values = $values;
        $settings->save();

        Settings::forget();
    }

    /** @return array<string, ?string> every field, for the admin form and for the activity log diff. */
    public static function all(): array
    {
        return [
            'logo_url'       => self::logoUrl(),
            'mark_url'       => self::get('mark_url'),
            'mark_light_url' => self::get('mark_light_url'),
            'primary_color'  => self::get('primary_color'),
        ];
    }

    private static function get(string $key): ?string
    {
        try {
            $values = \App\Models\PlatformSetting::current()->values ?? [];
        } catch (\Throwable) {
            return null;
        }

        return $values['brand'][$key] ?? null;
    }
}
