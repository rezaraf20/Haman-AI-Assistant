<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Widens the session cookie to cover the hamanai.com hosts, and only those.
 *
 * Signing up happens on hamanai.com and finishes in the panel on
 * app.hamanai.com. Those are different hosts, so a cookie scoped to the host
 * that issued it does not travel, and the new account arrives at the panel
 * logged out -- having just chosen a password. Setting Domain=.hamanai.com
 * fixes that, because a domain cookie is sent to every subdomain.
 *
 * The obvious way to do it is SESSION_DOMAIN in .env, and it is the wrong
 * way here. That value is static: it would stamp Domain=.hamanai.com onto
 * responses from api.arshanweb.ir too, where the browser rejects the cookie
 * outright as a domain mismatch. Every session-backed page on that host would
 * then break -- panel logins, and any form with a CSRF token, which would
 * start returning 419 with nothing in the logs to explain it. That host has to
 * keep working exactly as it does today, so it keeps a host-scoped cookie.
 *
 * Hence per request, before StartSession reads the config. Registered with
 * prepend: so it runs first wherever it appears -- the 'web' group, and now
 * both Filament panels' own ->middleware() arrays too (see
 * MiddlewareParityTest). Appending would place it after StartSession, where
 * changing session.domain has no effect at all.
 *
 * It was originally only registered on the 'web' group, and each Filament
 * panel builds its own separate middleware pipeline rather than reusing that
 * group -- so panel requests never widened the cookie, while /livewire/update
 * and the plain web.php routes did. Same cookie name, two different Domain
 * attributes depending on which kind of route answered: a browser holds both
 * as genuinely separate cookies and sends whichever one it likes, which is
 * where the intermittent 419s came from. clearStaleHostOnlyCookie() below
 * exists because fixing the code does not fix a browser that already has
 * both.
 *
 * API routes never reach this, being a separate stateless middleware group.
 */
class ShareSessionAcrossBrandDomains
{
    public function handle(Request $request, Closure $next)
    {
        $shared = (string) config('haman.domains.session');
        $onSharedHost = $shared !== '' && str_ends_with('.'.$request->getHost(), $shared);

        // Compare with a leading dot on both sides, so that "hamanai.com" and
        // "app.hamanai.com" match ".hamanai.com" while "evilhamanai.com"
        // does not -- a plain endsWith on the bare suffix would accept it.
        if ($onSharedHost) {
            config(['session.domain' => $shared]);
        }

        $response = $next($request);

        if ($onSharedHost) {
            $this->clearStaleHostOnlyCookie($response);
        }

        return $response;
    }

    /**
     * Explicitly expires whatever host-only session cookie a returning
     * browser is still holding from before this middleware covered the
     * panels.
     *
     * A code fix stops a NEW host-only cookie from ever being issued again,
     * but does nothing about one a browser captured on an earlier visit --
     * that cookie has no expiry date tied to a deploy, so it would otherwise
     * sit there, indistinguishable from the correct one, for as long as the
     * browser keeps it. Two Set-Cookie headers with the same name and
     * different Domain attributes are two different cookies as far as the
     * browser's storage is concerned, and it sends whichever one its own
     * internal ordering picks first -- which is exactly what made the 419s
     * intermittent rather than constant.
     *
     * Sending a second Set-Cookie for the same name, no Domain (matching the
     * stale cookie's own scope) and a past expiry, tells the browser to
     * delete that specific stored cookie. It runs alongside the real one
     * StartSession is setting in the same response, not instead of it.
     *
     * TODO(2027-01-01): safe to delete once this has been live for a full
     * session lifetime (2 hours) times a wide margin -- there is no way to
     * know a given browser received this response, only that enough of them
     * have by then.
     */
    private function clearStaleHostOnlyCookie($response): void
    {
        // headers is a property, not a method -- every real HTTP response
        // has one, but a Livewire/Symfony edge case could in principle
        // return something else, and this must never be why a request fails.
        if (!($response instanceof \Symfony\Component\HttpFoundation\Response)) {
            return;
        }

        $response->headers->setCookie(Cookie::create(
            name: config('session.cookie'),
            value: null,
            expire: 1, // a Unix timestamp in the past -- "delete this cookie"
            path: config('session.path', '/'),
            domain: null, // host-only, matching the stale cookie's own scope
            secure: (bool) config('session.secure', false),
            httpOnly: (bool) config('session.http_only', true),
            raw: false,
            sameSite: config('session.same_site'),
        ));
    }
}
