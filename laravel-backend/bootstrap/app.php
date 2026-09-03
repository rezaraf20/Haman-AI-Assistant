<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Applied to the whole 'web' group (not just each Filament panel's own
        // middleware array) specifically so it also covers Livewire's internal
        // AJAX update endpoint and the plain /portal/login route — both sit
        // outside any panel's route registration, and OtpLogin's component
        // methods (sendCode/verifyCode/completeProfile) run through the former,
        // not the page's own initial GET request.
        $middleware->web(append: [\App\Http\Middleware\SetLocale::class]);
        $middleware->alias([
            'auth.apikey'    => \App\Http\Middleware\AuthenticateTenantApiKey::class,
            'tenant.schema'  => \App\Http\Middleware\SetTenantSchema::class,
            'tenant.quota'   => \App\Http\Middleware\CheckTenantQuota::class,
            'webhook.verify' => \App\Http\Middleware\VerifyWebhookSignature::class,
            'chatbot.domain' => \App\Http\Middleware\ValidateChatbotDomain::class,
        ]);
        // The Filament admin panel (routes under /admin) needs guests redirected to its own
        // login page; the customer portal (/portal) redirects to the phone+OTP login flow
        // instead of Filament's own (unused there, see CustomerPanelProvider); every other
        // (API) route keeps the plain JSON 401 the app has always used.
        $middleware->redirectGuestsTo(fn(Request $req) => match (true) {
            $req->is('admin*')   => '/admin/login',
            $req->is('portal*')  => '/portal/login',
            default               => response()->json(['error' => 'Unauthenticated'], 401),
        });
        // Stack is Apache (host, DirectAdmin) -> nginx container -> php-fpm, all on the same
        // private docker network. Trust that internal hop so $request->ip() resolves the real
        // visitor IP from X-Forwarded-For (Apache always appends the true peer IP as the
        // rightmost entry, so a client-supplied XFF header cannot spoof this).
        $middleware->trustProxies(
            at: ['192.168.0.0/16', '172.16.0.0/12', '10.0.0.0/8'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                if ($e instanceof ValidationException) {
                    return response()->json(['error' => 'Validation failed', 'errors' => $e->errors()], 422);
                }
                if ($e instanceof ModelNotFoundException) {
                    return response()->json(['error' => 'Not found'], 404);
                }
                if ($e instanceof AuthenticationException) {
                    return response()->json(['error' => 'Unauthenticated'], 401);
                }
                // Anything else carrying a real HTTP status (429 from the
                // throttle:* middleware, 403/404 thrown directly, etc.) was
                // previously falling through to the generic 500 branch below —
                // the three explicit cases above didn't cover it, so a rate
                // limit that should read 429 read as a bare 500 instead.
                // Preserve both the status and any headers (e.g. Retry-After).
                if ($e instanceof HttpExceptionInterface) {
                    $status = $e->getStatusCode();
                    $message = config('app.debug') ? $e->getMessage() : ($status === 429 ? 'Too many requests' : 'Request failed');
                    return response()->json(['error' => $message ?: 'Request failed'], $status, $e->getHeaders());
                }
                // file/line/raw message are internal server structure — fine for
                // local debugging, a real leak in production (stack trace via
                // guesswork, library versions, absolute paths). Only ever include
                // them when APP_DEBUG is explicitly on.
                if (config('app.debug')) {
                    return response()->json([
                        'error' => $e->getMessage(),
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                    ], 500);
                }
                return response()->json(['error' => 'Internal server error'], 500);
            }
        });
    })
    ->create();