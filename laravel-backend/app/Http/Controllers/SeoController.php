<?php
namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * robots.txt and sitemap.xml.
 *
 * Routes rather than files in public/, because both have to name the domain
 * they are served from and that domain is about to change: the site runs on
 * the API host today and moves to its own later. A static file would have to
 * be rewritten by hand at that moment, and would be wrong in the meantime on
 * whichever host it was not written for.
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

        foreach (self::DISALLOWED as $path) {
            $lines[] = 'Disallow: ' . $path;
        }

        $lines[] = 'Allow: /$';
        $lines[] = '';
        $lines[] = 'Sitemap: ' . url('/sitemap.xml');

        return $this->text(implode("\n", $lines) . "\n", 'text/plain');
    }

    public function sitemap(): Response
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach (self::PUBLIC_PATHS as $path => $frequency) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . e(url($path)) . '</loc>';
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
