<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Replaces SetPersianLocale now that the panel is bilingual (fa/en).
// Precedence: logged-in user's saved users.locale column, then the
// browser's Accept-Language header (only if it names a locale we actually
// support), then config('app.locale') as the last resort. Filament's own
// bundled translations (including layout.php's RTL/LTR direction) key off
// this same app()->setLocale() call — nothing else needs to touch it.
class SetLocale {
    private const SUPPORTED = ['fa', 'en'];

    public function handle(Request $request, Closure $next) {
        app()->setLocale($this->resolveLocale($request));
        return $next($request);
    }

    private function resolveLocale(Request $request): string {
        $user = $request->user();
        if ($user && in_array($user->locale, self::SUPPORTED, true)) {
            return $user->locale;
        }

        $preferred = $request->getPreferredLanguage(self::SUPPORTED);
        if ($preferred) {
            return $preferred;
        }

        $default = config('app.locale');
        return in_array($default, self::SUPPORTED, true) ? $default : 'fa';
    }
}
