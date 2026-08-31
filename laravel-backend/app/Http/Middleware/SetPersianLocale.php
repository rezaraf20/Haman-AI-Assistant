<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// The customer portal is Persian-only. Filament already ships full Persian
// translations (vendor/filament/*/resources/lang/fa/*.php) including
// layout.php's 'direction' => 'rtl' — the panel only renders RTL when the
// active app locale is actually 'fa', which nothing was ever setting.
class SetPersianLocale {
    public function handle(Request $request, Closure $next) {
        app()->setLocale('fa');
        return $next($request);
    }
}
