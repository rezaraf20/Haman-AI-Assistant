<?php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
    }
}
