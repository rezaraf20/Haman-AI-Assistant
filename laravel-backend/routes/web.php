<?php
// Intentionally minimal. The Filament admin panel (app/Providers/Filament/AdminPanelProvider.php)
// registers its own routes onto the 'web' middleware group programmatically — this file's job
// is only to make bootstrap/app.php's withRouting(web: ...) activate the standard session/CSRF
// middleware stack that Filament (and any future browser-facing page) needs.

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LandingSignupController;
use App\Http\Controllers\PaymentController;
use App\Http\Middleware\SetLocale;
use App\Services\TrendsService;

// The public site. Until this existed there was nowhere to send someone who
// became interested: the only public pages were a login form and a payment
// callback.
Route::get('/', [LandingController::class, 'index'])
    ->middleware([SetLocale::class, 'throttle:public-read'])
    ->name('landing');

// Email + password signup, the second way in. OtpLogin only accepts an
// Iranian mobile number, which is a hard stop for anyone outside Iran.
// Throttled with the same limiter as the API's register, since this creates
// a tenant and a Postgres schema exactly as that one does.
Route::post('/signup', [LandingSignupController::class, 'register'])
    ->middleware([SetLocale::class, 'throttle:register'])
    ->name('landing.register');

// Signed and expiring; the hash covers the address the link was issued for.
Route::get('/verify-email/{id}/{hash}', [LandingSignupController::class, 'verify'])
    ->middleware([SetLocale::class, 'signed', 'throttle:public-read'])
    ->name('verify.email');

Route::post('/verify-email/resend', [LandingSignupController::class, 'resend'])
    ->middleware([SetLocale::class, 'auth', 'throttle:public-read'])
    ->name('verify.email.resend');

// Zarinpal redirects the end user's browser here after payment — deliberately
// outside any auth guard (see PaymentController for why that's safe).
// Route::any accepted seven verbs on an endpoint that makes a server-to-
// server verify call to Zarinpal per request. A browser redirect back from
// a gateway is a GET; POST is kept only because gateways occasionally use
// it. Throttled because each call costs an outbound request.
Route::match(['get', 'post'], '/payments/zarinpal/callback', [PaymentController::class, 'zarinpalCallback'])
    ->middleware('throttle:public-read')
    ->name('payments.zarinpal.callback');

// Customer portal's login/signup — phone+SMS-OTP (see CustomerPanelProvider
// for why this isn't Filament's own login page, and app/Livewire/OtpLogin.php
// for that flow) for Persian visitors, email+password (app/Livewire/
// EmailLogin.php) for everyone else, chosen by the resolved locale (see
// SetLocale) but always escapable via ?method=phone/email — a Persian
// speaker whose browser happens to report English, or vice versa, must
// never be stuck looking at a login form for a method their account
// doesn't use. Already-authenticated visitors get bounced straight to the
// portal instead of seeing a login form again.
Route::get('/portal/login', function () {
    if (auth()->check()) return redirect('/portal');

    $method = request('method');
    if (!in_array($method, ['phone', 'email'], true)) {
        $method = session('portal_auth_method');
    }
    if (!in_array($method, ['phone', 'email'], true)) {
        $method = app()->getLocale() === 'fa' ? 'phone' : 'email';
    }
    session(['portal_auth_method' => $method]);

    return view('auth.otp-login-page', ['method' => $method]);
})->name('portal.login')->middleware([SetLocale::class, 'throttle:public-read']);

/**
 * The Trends report as a standalone, print-styled page — this is the "PDF"
 * half of the report's export.
 *
 * Deliberately a print stylesheet the browser turns into a PDF rather than
 * a server-side PDF library: the report is mostly Persian, and the PHP PDF
 * libraries available here either cannot shape Arabic-script glyphs or
 * cannot lay them out right-to-left, which would hand the merchant a
 * beautifully formatted page of broken text to show their buying team.
 * The browser's own engine renders it correctly, and "Save as PDF" is one
 * keystroke from here.
 */
Route::get('/portal/trends/print', function () {
    $range = request()->query('range', '30d');
    if (!array_key_exists($range, TrendsService::RANGES)) $range = '30d';

    $tenant = auth()->user()->tenant;
    $data = app(TrendsService::class)->get($tenant->schema_name, $range);

    return view('reports.trends-print', [
        'data'        => $data,
        'range'       => $range,
        'tenantName'  => $tenant->name,
    ]);
})->middleware(['web', 'auth', SetLocale::class])->name('portal.trends.print');
