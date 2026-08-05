<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth.apikey'    => \App\Http\Middleware\AuthenticateTenantApiKey::class,
            'tenant.schema'  => \App\Http\Middleware\SetTenantSchema::class,
            'tenant.quota'   => \App\Http\Middleware\CheckTenantQuota::class,
            'webhook.verify' => \App\Http\Middleware\VerifyWebhookSignature::class,
        ]);
        // The Filament admin panel (routes under /admin) needs guests redirected to its own
        // login page; every other (API) route keeps the plain JSON 401 the app has always used.
        $middleware->redirectGuestsTo(fn(Request $req) => $req->is('admin*')
            ? '/admin/login'
            : response()->json(['error' => 'Unauthenticated'], 401));
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
                return response()->json([
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ], 500);
            }
        });
    })
    ->create();