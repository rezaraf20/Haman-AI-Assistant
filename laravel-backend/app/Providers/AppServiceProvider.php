<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {}
    public function boot(): void {
        if($this->app->isProduction()) URL::forceScheme('https');
        Model::preventLazyLoading(!$this->app->isProduction());
        \DB::prohibitDestructiveCommands($this->app->isProduction());

        // Hard floor for the public (unauthenticated) chat widget endpoints —
        // independent of ChatController's per-chatbot widget_config throttle,
        // which is admin-opt-in and a no-op when unset. This applies
        // regardless, so a chatbot with no configured limit still can't be
        // hammered for free. Per-chatbot config can only make it stricter, by
        // adding its own RateLimiter::hit() check on top of this.
        RateLimiter::for('chat-session', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });
        RateLimiter::for('chat-message', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip() . ':' . ($request->input('chatbot_id') ?? 'unknown'));
        });
    }
}
