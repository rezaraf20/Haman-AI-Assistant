<?php
namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the public chat endpoints out of service while keeping the panels up.
 *
 * Deliberately narrower than Laravel's own `artisan down`: the point of this
 * switch is to stop the widget answering customers during a bad deploy or a
 * provider outage, without also locking the platform owner out of the admin
 * panel they need in order to fix it.
 *
 * 503 with Retry-After, so the widget's own retry logic and any crawler treat
 * it as temporary rather than as the chatbot being gone.
 */
class MaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Settings::get('system.maintenance_mode')) {
            return $next($request);
        }

        $message = Settings::get('system.maintenance_message')
            ?: __('common.maintenance_default_message');

        return response()->json([
            'success' => false,
            'error'   => 'maintenance',
            'message' => $message,
        ], 503)->header('Retry-After', '300');
    }
}
