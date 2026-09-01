<?php
// Intentionally minimal. The Filament admin panel (app/Providers/Filament/AdminPanelProvider.php)
// registers its own routes onto the 'web' middleware group programmatically — this file's job
// is only to make bootstrap/app.php's withRouting(web: ...) activate the standard session/CSRF
// middleware stack that Filament (and any future browser-facing page) needs.

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;

// Zarinpal redirects the end user's browser here after payment — deliberately
// outside any auth guard (see PaymentController for why that's safe).
Route::any('/payments/zarinpal/callback', [PaymentController::class, 'zarinpalCallback'])
    ->name('payments.zarinpal.callback');

// Customer portal's phone+SMS-OTP login/signup (see CustomerPanelProvider for
// why this isn't Filament's own login page, and app/Livewire/OtpLogin.php for
// the actual flow). Already-authenticated visitors get bounced straight to
// the portal instead of seeing a login form again.
Route::get('/portal/login', fn () => auth()->check()
    ? redirect('/portal')
    : view('auth.otp-login-page')
)->name('portal.login');
