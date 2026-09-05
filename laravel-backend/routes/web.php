<?php
// Intentionally minimal. The Filament admin panel (app/Providers/Filament/AdminPanelProvider.php)
// registers its own routes onto the 'web' middleware group programmatically — this file's job
// is only to make bootstrap/app.php's withRouting(web: ...) activate the standard session/CSRF
// middleware stack that Filament (and any future browser-facing page) needs.

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
use App\Http\Middleware\SetLocale;

// Zarinpal redirects the end user's browser here after payment — deliberately
// outside any auth guard (see PaymentController for why that's safe).
Route::any('/payments/zarinpal/callback', [PaymentController::class, 'zarinpalCallback'])
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
})->name('portal.login')->middleware(SetLocale::class);
