<?php
namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\SetPersianLocale;

// Second Filament panel, entirely separate from AdminPanelProvider — tenant
// customers log in here and only ever see their own tenant's data. Resources/
// Pages live under app/Filament/Customer/ (a different namespace/directory
// than app/Filament/Resources/) specifically so this panel's discovery below
// never picks up the owner-only admin resources.
class CustomerPanelProvider extends PanelProvider {
    public function panel(Panel $panel): Panel {
        return $panel
            ->id('customer')
            ->path('portal')
            ->brandName('Haman AI — پرتال مشتری')
            // No ->login() — auth is phone+SMS-OTP via the plain Livewire flow
            // at routes/web.php's /portal/login (app/Livewire/OtpLogin.php),
            // not Filament's built-in email/password login page. It still
            // establishes a normal 'web' guard session via Auth::login(), so
            // Filament's Authenticate middleware below recognizes it exactly
            // like its own login would — bootstrap/app.php's redirectGuestsTo
            // sends unauthenticated /portal* visitors to that route instead.
            ->colors(['primary' => '#1B3A6B'])
            // discoverPages() only picks up files under Filament/Customer/Pages
            // — it does NOT auto-register Filament's own built-in Dashboard
            // page (that only happens when a panel's pages are left at their
            // untouched default). Without this line, portal/ silently had no
            // dashboard route at all: the root path's RedirectToHomeController
            // just fell through to the first navigation item (Wallet), so the
            // AccountOverview widget below was never mounted anywhere.
            ->pages([\Filament\Pages\Dashboard::class])
            ->discoverResources(in: app_path('Filament/Customer/Resources'), for: 'App\\Filament\\Customer\\Resources')
            ->discoverPages(in: app_path('Filament/Customer/Pages'), for: 'App\\Filament\\Customer\\Pages')
            ->discoverWidgets(in: app_path('Filament/Customer/Widgets'), for: 'App\\Filament\\Customer\\Widgets')
            ->middleware([
                SetPersianLocale::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
