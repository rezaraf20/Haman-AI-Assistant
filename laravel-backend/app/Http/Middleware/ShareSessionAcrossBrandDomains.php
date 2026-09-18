<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

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
 * prepend: so it runs first in the web group; appending would place it after
 * StartSession, where changing session.domain has no effect at all.
 *
 * API routes never reach this, being a separate stateless middleware group.
 */
class ShareSessionAcrossBrandDomains
{
    public function handle(Request $request, Closure $next)
    {
        $shared = (string) config('haman.domains.session');

        // Compare with a leading dot on both sides, so that "hamanai.com" and
        // "app.hamanai.com" match ".hamanai.com" while "evilhamanai.com"
        // does not -- a plain endsWith on the bare suffix would accept it.
        if ($shared !== '' && str_ends_with('.'.$request->getHost(), $shared)) {
            config(['session.domain' => $shared]);
        }

        return $next($request);
    }
}
