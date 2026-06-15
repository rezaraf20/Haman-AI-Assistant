<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
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
        $middleware->redirectGuestsTo(fn() => response()->json(['error' => 'Unauthenticated'], 401));
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