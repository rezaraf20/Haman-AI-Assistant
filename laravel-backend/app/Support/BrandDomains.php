<?php
namespace App\Support;

use Illuminate\Support\Facades\Request;

/**
 * Where to send someone who is on one of our hosts and needs another.
 *
 * The panels answer on every hostname the platform has -- nothing is bound to
 * a domain in Filament, deliberately, so that a customer who bookmarked
 * api.arshanweb.ir/portal keeps the page they bookmarked. What changes is
 * only where the links point.
 *
 * On the hamanai.com hosts a panel link is absolute and goes to
 * app.hamanai.com, which is what makes the landing page and the panel feel
 * like two parts of one site. On any other host -- api.arshanweb.ir -- links
 * stay relative, so that host remains entirely self-contained and nothing
 * sends a visitor across a domain boundary their session cookie cannot follow.
 */
class BrandDomains
{
    /** A link to a panel page, absolute or relative depending on the host. */
    public static function appUrl(string $path = '/'): string
    {
        $path = '/'.ltrim($path, '/');

        if (!self::onBrandHost()) return url($path);

        $host = (string) config('haman.domains.app');
        if ($host === '') return url($path);

        return 'https://'.$host.$path;
    }

    /** The API address given to customers to paste into their site. */
    public static function publicApiUrl(string $path = ''): string
    {
        $base = rtrim((string) config('haman.domains.api_public'), '/');

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    /**
     * A link to the public site, always on the landing host.
     *
     * Canonical links, the sitemap and og:url all use this. The landing page
     * renders on all four hostnames -- nothing stops it -- so leaving these
     * to follow the request would offer search engines four identical sites
     * and let them choose which is real.
     */
    public static function landingUrl(string $path = '/'): string
    {
        $path = '/'.ltrim($path, '/');
        $host = (string) config('haman.domains.landing');

        return $host === '' ? url($path) : 'https://'.$host.$path;
    }

    /** True when this request arrived on the public site's own hostname. */
    public static function onLandingHost(): bool
    {
        $host = (string) config('haman.domains.landing');
        if ($host === '') return true;

        $current = Request::getHost();

        return $current === $host || $current === 'www.'.$host;
    }

    /** True when the current request arrived on a hamanai.com hostname. */
    public static function onBrandHost(): bool
    {
        $shared = (string) config('haman.domains.session');
        if ($shared === '') return false;

        return str_ends_with('.'.Request::getHost(), $shared);
    }
}
