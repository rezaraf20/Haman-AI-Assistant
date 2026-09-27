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

        // RefreshDatabase resets the database between tests, but PHPUnit
        // reuses the same application instance for the whole run, so
        // anything cached outside the database survives across test
        // methods unless it is cleared here too. Two real cross-test
        // failures traced to exactly this: RateLimiter::hit() (login/IP and
        // order-status/IP throttles) piling up hits across every test that
        // happens to share a key, and Settings::$cache -- a private static
        // property, not the Cache facade, so it survives even a fresh
        // cache store -- serving one test's Settings::set() to the next.
        // Cache::flush() only touches the array-store instance this process
        // is using (CACHE_STORE is forced to "array" in phpunit.xml), never
        // a shared Redis, so this cannot bleed into anything real.
        Cache::flush();
        Settings::forget();
    }
}
