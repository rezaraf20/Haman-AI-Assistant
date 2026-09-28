<?php
namespace Tests;

use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a test database.
     *
     * This suite uses RefreshDatabase, which drops every table. phpunit.xml
     * names the database it should use, but PHPUnit's <env> entries are
     * advisory by default and are skipped whenever the surrounding process
     * already defines the variable — so running `php artisan test` inside a
     * deployed container pointed the whole suite at the live application
     * database, and the only thing standing between that and an empty
     * production was migrate:fresh declining to run while APP_ENV=production.
     *
     * The database name is checked at runtime rather than trusted from
     * config, so this holds no matter where the value came from.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::selectOne('select current_database() as name')->name;

        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against '{$database}': the name of a test database must end in _test. "
                . 'This suite drops tables.'
            );
        }

        // The same class of mistake, for the stores Cache::flush() below
        // actually touches. There is no separate "test Redis" the way there
        // is a separate haman_test database -- cache/session/queue all sit
        // on the SAME physical Redis as production, distinguished only by
        // which store/driver is selected. So the invariant checked here
        // isn't "is this Redis production" (nothing here could tell), it's
        // "are these three drivers the safe, isolated values a test run
        // requires" -- exactly the three that silently resolved to
        // production's redis tonight when the documented -e flags were
        // missing (see DEPLOY.md), with Cache::flush() below then running
        // against real, shared production cache data instead of a private
        // in-process store. A test suite must never be able to do that
        // again regardless of how it was invoked.
        $unsafe = array_filter([
            'CACHE_STORE (config(cache.default))'     => config('cache.default') !== 'array',
            'SESSION_DRIVER (config(session.driver))' => config('session.driver') !== 'array',
            'QUEUE_CONNECTION (config(queue.default))' => config('queue.default') !== 'sync',
        ]);
        if ($unsafe) {
            throw new RuntimeException(
                'Refusing to run tests: ' . implode(', ', array_keys($unsafe)) . ' '
                . (count($unsafe) === 1 ? 'is' : 'are') . " not set to the safe test value.\n"
                . 'This usually means the suite was started without the full -e flag list DEPLOY.md documents '
                . "(docker-compose's env_file wins over phpunit.xml's <env> here) -- run it exactly as documented there, "
                . 'never with docker compose exec on the deployed container.'
            );
        }

        // RefreshDatabase resets the database between tests, but PHPUnit
        // reuses the same application instance for the whole run, so
        // anything cached outside the database survives across test
        // methods unless it is cleared here too. Two real cross-test
        // failures traced to exactly this: RateLimiter::hit() (login/IP and
        // order-status/IP throttles) piling up hits across every test that
        // happens to share a key, and Settings::$cache -- a private static
        // property, not the Cache facade, so it survives even a fresh
        // cache store -- serving one test's Settings::set() to the next.
        // Cache::flush() is only safe here because CACHE_STORE=array is
        // passed as a real -e flag on every documented test invocation (see
        // DEPLOY.md) -- phpunit.xml's own <env> entry for it does NOT
        // reliably win against a container env var already set by
        // env_file, the same precedence gap DEPLOY.md documents for
        // QUEUE_CONNECTION/SESSION_DRIVER/ZARINPAL_MERCHANT_ID. Running
        // this suite without that flag list would make Cache::flush() hit
        // the real shared Redis 'cache' connection instead.
        Cache::flush();
        Settings::forget();
    }
}
