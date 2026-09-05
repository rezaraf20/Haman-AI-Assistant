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

class AdminPanelProvider extends PanelProvider {
    public function panel(Panel $panel): Panel {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('hamman.brand.name'))
            ->login()
            ->colors(['primary' => config('hamman.brand.primary_color')])
            // Not ->font(): that method's $family parameter is a plain
            // `string`, not `string|Closure` — Filament needs the name eagerly,
            // at boot, to register the font asset, which is before SetLocale
            // has run (panel() executes during service-provider boot, ahead of
            // the request pipeline). A render hook's closure runs per-request
            // instead, which is what locale-dependent output actually needs.
            //
            // Overriding just body/.fi-body's font-family does NOT work: every
            // Filament/Tailwind utility class resolves fonts via the
            // `--font-family` CSS custom property (compiled CSS is littered
            // with `font-family:var(--font-family),ui-sans-serif,...`), which
            // Filament itself sets via its own `<style>:root{--font-family:
            // 'Inter';...}</style>` block earlier in <head>. This render hook
            // runs at HEAD_END (after that block), so redefining the same
            // custom property on :root here wins by source order.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => new HtmlString(app()->getLocale() === 'fa'
                    ? '<link rel="stylesheet" href="https://fonts.bunny.net/css?family=vazirmatn:400,500,600,700"><style>:root{--font-family:"Vazirmatn"}</style>'
                    : '<link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600,700"><style>:root{--font-family:"Inter"}</style>'),
            )
            // App\Filament\Pages\Dashboard (extends Filament's own) now lives
            // inside the discoverPages() directory below, so it's picked up
            // automatically — no separate ->pages([...]) registration needed
            // (that was only required before because the stock vendor
            // Dashboard class lives outside this directory entirely).
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->navigationGroups([
                __('panel.nav_group_customers'),
                __('panel.nav_group_finance'),
                __('panel.nav_group_support'),
                __('panel.nav_group_infrastructure'),
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
