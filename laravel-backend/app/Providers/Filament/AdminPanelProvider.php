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
use App\Http\Middleware\ShareSessionAcrossBrandDomains;
use App\Support\Brand;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

class AdminPanelProvider extends PanelProvider {
    public function panel(Panel $panel): Panel {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('haman.brand.name'))
            ->brandLogo(fn () => view('partials.brand-logo', ['height' => 28]))
            ->darkModeBrandLogo(fn () => view('partials.brand-logo', ['height' => 28, 'variant' => 'light']))
            ->brandLogoHeight('1.75rem')
            ->favicon(Brand::markUrl())
            ->login()
            ->colors(['primary' => Brand::primaryColor()])
            // Bell icon + dropdown in the topbar, backed by the notifications
            // table (see its migration). First real use: alerting the
            // platform admin when an LLM provider auto-disables itself after
            // repeated failures (haman:notify-disabled-providers). Polls
            // every 30s rather than the 60s default so a fresh alert doesn't
            // sit unnoticed for a full minute on a page already open.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
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
            // brand.css carries the @font-face rules for the self-hosted
            // Poppins/Roboto/Vazirmatn files — one stylesheet for both
            // languages now, because font-family fallback is what splits
            // Latin from Persian per character (see brand.css's own
            // comment), not a link swapped by locale. Roboto first: this is
            // Filament's general UI font, not a page of headings, so the
            // body typeface leads and Vazirmatn is what a Persian character
            // falls through to.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => new HtmlString('<link rel="stylesheet" href="/css/brand.css"><style>:root{--font-family:"Roboto","Vazirmatn",sans-serif}</style>'),
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
            // This panel builds its OWN middleware pipeline — it does not
            // reuse bootstrap/app.php's 'web' group at all — which is why
            // two fixes made there (ShareSessionAcrossBrandDomains,
            // SetLocale's position) never reached these routes. See
            // MiddlewareParityTest, which now fails the build if this array
            // and the 'web' group ever drift apart again without the
            // difference being named in its own allowlist.
            ->middleware([
                // Must run before StartSession, which reads
                // config('session.domain') when it opens the session — same
                // reasoning as its `prepend:` registration on the 'web'
                // group. Without this here, every panel response set the
                // session cookie's Domain from whatever the base config
                // says (nothing, on this app), while /livewire/update and
                // the plain web.php routes set it to .hamanai.com — two
                // different Domain attributes on one cookie name is two
                // cookies in the browser, and which one a request happens
                // to send back is when the reported 419s came from.
                ShareSessionAcrossBrandDomains::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Guest-only coverage: the login page never enters
                // ->authMiddleware() below, so it needs its own locale pass
                // here to render in the right language/direction. This runs
                // a second time — harmlessly, app()->setLocale() is
                // idempotent — on every authenticated request, because the
                // authoritative pass is the one after Authenticate below.
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // After Authenticate, not before: SetLocale reads
                // $request->user()->locale, and placing it here — rather
                // than trusting the lazy session-guard resolution the copy
                // above relies on — means it only ever sees a session
                // AuthenticateSession has already accepted, never one that
                // middleware is a step away from invalidating.
                SetLocale::class,
            ]);
    }
}
