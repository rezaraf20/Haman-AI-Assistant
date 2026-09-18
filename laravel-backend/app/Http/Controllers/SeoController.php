<?php
namespace App\Http\Controllers;

use App\Support\BrandDomains;
use Illuminate\Http\Response;

/**
 * robots.txt and sitemap.xml.
 *
 * Routes rather than files in public/, because both have to name a domain and
 * the platform answers on four of them. A static file would name one and be
 * wrong on the other three.
 *
 * Only the landing host invites indexing. The same application serves the
 * panels on app.hamanai.com and the API on two more hostnames, and every one
 * of them can render the landing page -- so without this, one site would be
 * offered to search engines four times over as four sets of duplicate pages.
 * Those hosts disallow everything instead, and the canonical link in the
 * layout names the landing host regardless of who served the page.
 *
 * Only the public pages are listed. The panels and the API are disallowed --
 * not as a security measure, since robots.txt is only a request, but because
 * a login form in a search result helps nobody.
 */
class SeoController extends Controller
{
    /** Long, because neither changes between deploys. */
    private const CACHE_SECONDS = 86400;

    /** The pages that should be indexed, as path => change frequency. */
    private const PUBLIC_PATHS = [
        '/' => 'weekly',
    ];

    private const DISALLOWED = [
        '/admin',
        '/portal',
        '/api',
        '/verify-email',
        '/payments',
    ];

    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        if (!BrandDomains::onLandingHost()) {
            // A panel host or an API host. Nothing here is for a search
            // engine, and everything here is a copy of something that is.
            $lines[] = 'Disallow: /';

            return $this->text(implode(PHP_EOL, $lines) . PHP_EOL, 'text/plain');
        }

        foreach (self::DISALLOWED as $path) {
            $lines[] = 'Disallow: ' . $path;
        }

        $lines[] = 'Allow: /$';
        $lines[] = '';
        $lines[] = 'Sitemap: ' . BrandDomains::landingUrl('/sitemap.xml');

        return $this->text(implode("\n", $lines) . "\n", 'text/plain');
    }

    public function sitemap(): Response
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach (self::PUBLIC_PATHS as $path => $frequency) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . e(BrandDomains::landingUrl($path)) . '</loc>';
            $xml[] = '    <changefreq>' . $frequency . '</changefreq>';
            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return $this->text(implode("\n", $xml) . "\n", 'application/xml');
    }

    private function text(string $body, string $contentType): Response
    {
        return response($body, 200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'public, max-age=' . self::CACHE_SECONDS);
    }
}
