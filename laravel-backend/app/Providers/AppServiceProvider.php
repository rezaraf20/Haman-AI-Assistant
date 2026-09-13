<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\{Failed, Login, Logout};
use App\Listeners\RecordPlatformAuthActivity;
use App\Support\{MailSettings, Settings};

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
        // Read per request rather than captured once at boot, so changing
        // the number in the settings page takes effect without a restart.
        RateLimiter::for('chat-session', function (Request $request) {
            return Limit::perMinute((int) Settings::get('limits.chat_session_per_minute'))->by($request->ip());
        });
        RateLimiter::for('chat-message', function (Request $request) {
            return Limit::perMinute((int) Settings::get('limits.chat_message_per_minute'))
                ->by($request->ip() . ':' . ($request->input('chatbot_id') ?? 'unknown'));
        });

        // Added by the abuse audit. Every limiter below guards an endpoint
        // that was reachable without authentication and either cost money
        // or could be brute-forced.

        // Password guessing. Keyed on IP AND the address being tried, so one
        // attacker cannot lock every account out by hammering the same IP,
        // and cannot spread an attack on one account across the keyspace.
        RateLimiter::for('login', function (Request $request) {
            $perMinute = (int) Settings::get('limits.login_attempts_per_minute');
            return [
                Limit::perMinute($perMinute)->by('login:ip:' . $request->ip()),
                Limit::perMinute($perMinute)->by('login:email:' . strtolower((string) $request->input('email'))),
            ];
        });

        // Registration creates a tenant AND a Postgres schema full of tables.
        // It was the most expensive unauthenticated operation in the system
        // and had no limit at all.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perDay((int) Settings::get('limits.register_per_ip_per_day'))->by('register:' . $request->ip());
        });

        // The customer portal's SMS login. SmsService only enforced a
        // 60-second cooldown per phone number, so cycling numbers sent
        // unlimited SMS on the PLATFORM's account — no tenant to bill.
        RateLimiter::for('portal-otp', function (Request $request) {
            return Limit::perDay((int) Settings::get('limits.portal_otp_per_ip_per_day'))->by('portal-otp:' . $request->ip());
        });

        // A valid API key could call sync as fast as it liked, and every
        // call embeds text at the platform's expense.
        RateLimiter::for('plugin-api', function (Request $request) {
            $key = $request->attributes->get('api_key_id') ?? $request->ip();
            return Limit::perMinute((int) Settings::get('limits.sync_requests_per_minute'))->by('plugin-api:' . $key);
        });

        // Cheap public reads, but unbounded is still unbounded.
        RateLimiter::for('public-read', function (Request $request) {
            return Limit::perMinute((int) Settings::get('limits.public_read_per_minute'))->by($request->ip());
        });

        // SMTP credentials live in the settings table, not env — see
        // config/mail.php for why this has to happen at boot instead.
        MailSettings::apply();

        // Platform staff sign-ins, sign-outs and failed attempts. Bound to
        // the guard's events rather than to a login screen, so every way
        // into the panel is covered by one listener.
        Event::listen(Login::class,  [RecordPlatformAuthActivity::class, 'onLogin']);
        Event::listen(Logout::class, [RecordPlatformAuthActivity::class, 'onLogout']);
        Event::listen(Failed::class, [RecordPlatformAuthActivity::class, 'onFailed']);
    }
}
