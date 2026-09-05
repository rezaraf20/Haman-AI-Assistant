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
use App\Http\Middleware\SetLocale;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

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
            ->brandName(fn () => config('hamman.brand.name') . ' — ' . __('common.customer_portal'))
            // No ->login() — auth is phone+SMS-OTP via the plain Livewire flow
            // at routes/web.php's /portal/login (app/Livewire/OtpLogin.php),
            // not Filament's built-in email/password login page. It still
            // establishes a normal 'web' guard session via Auth::login(), so
            // Filament's Authenticate middleware below recognizes it exactly
            // like its own login would — bootstrap/app.php's redirectGuestsTo
            // sends unauthenticated /portal* visitors to that route instead.
            ->colors(['primary' => config('hamman.brand.primary_color')])
            // See AdminPanelProvider for why this is a render hook and not
            // ->font() (whose $family parameter is a plain, eagerly-evaluated
            // string, not string|Closure), and why it overrides the
            // `--font-family` custom property rather than body/.fi-body's
            // font-family — Filament's compiled CSS reads fonts from that
            // variable on every utility class, not from inheritance.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => new HtmlString(app()->getLocale() === 'fa'
                    ? '<link rel="stylesheet" href="https://fonts.bunny.net/css?family=vazirmatn:400,500,600,700"><style>:root{--font-family:"Vazirmatn"}</style>'
                    : '<link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600,700"><style>:root{--font-family:"Inter"}</style>'),
            )
            // App\Filament\Customer\Pages\Dashboard (extends Filament's own)
            // now lives inside the discoverPages() directory below, so it's
            // picked up automatically as the panel's root page — no separate
            // ->pages([...]) registration needed (that was only required
            // before because the stock vendor Dashboard class lives outside
            // this directory entirely; without it, portal/ had no dashboard
            // route at all and fell through to the first nav item).
            ->discoverResources(in: app_path('Filament/Customer/Resources'), for: 'App\\Filament\\Customer\\Resources')
            ->discoverPages(in: app_path('Filament/Customer/Pages'), for: 'App\\Filament\\Customer\\Pages')
            ->discoverWidgets(in: app_path('Filament/Customer/Widgets'), for: 'App\\Filament\\Customer\\Widgets')
            ->navigationGroups([
                __('panel.nav_group_customer_chatbots'),
                __('panel.nav_group_customer_wallet'),
                __('panel.nav_group_customer_account'),
            ])
            ->middleware([
                SetLocale::class,
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
