<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        self::guardTestDatabase();

        return $app;
    }

    /**
     * Refuse to run against anything but a test database.
     *
     * RefreshDatabase drops and re-migrates every table, so a misconfigured connection does not fail
     * a test — it silently destroys whatever database it reached. phpunit.xml pins DB_DATABASE to
     * `helbaron_test`, but a stray exported DB_DATABASE, a hand-edited phpunit.xml or a future
     * connection default could still point the suite at development data. This runs from
     * createApplication(), which Laravel calls BEFORE setUpTraits() boots RefreshDatabase, so the
     * abort happens while the database is still untouched.
     *
     * Accepts any name ending in `_test` (covering the `_test_{token}` databases `artisan test
     * --parallel` derives per worker) and in-memory SQLite.
     */
    private static function guardTestDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === ':memory:' || str_ends_with($database, '_test') || (bool) preg_match('/_test_\d+$/', $database)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against database "%s" on connection "%s". The suite uses '
            .'RefreshDatabase and would destroy it. Test databases must end in "_test" — see the '
            .'DB_DATABASE override in phpunit.xml.',
            $database,
            $connection,
        ));
    }

    /**
     * Per-test state isolation.
     *
     * RefreshDatabase resets the DATABASE between tests, but three pieces of process- and
     * environment-level state leak across tests and are the sole cause of every failure recorded in
     * BASELINE_DEFECTS.md. Pin all three deterministically here so no test can observe another's
     * state — none of this weakens a test, it only removes cross-test bleed:
     *
     *   1. Cache. CACHE_STORE=array persists for the life of the process (a whole serial run, or a
     *      whole ParaTest worker). KpiEngine, the report cache and the notification rate limiter all
     *      cache by a key that is stable across tests (metric + date range, report params, user id),
     *      so a value one test computes is served to the next. Flushing per test makes every read
     *      recompute from this test's freshly-migrated rows (fixes Analytics\EventToSnapshotTest, and
     *      the parallel Security\RateLimitTest bleed).
     *
     *   2. Spatie permission/role registrar. The registrar caches the resolved permission and role
     *      rows (ids included) for the life of the process. When a test re-seeds RolePermissionSeeder,
     *      the freshly-inserted rows get NEW ids, but a stale cache still hands out the previous run's
     *      ids — so assignRole()/givePermissionTo() write role_has_permissions rows pointing at ids
     *      that no longer exist (FK violation), and hasPermission()/hasRole() resolve against a stale
     *      map (PermissionDoesNotExist, and the Filament super_admin bypass silently failing in
     *      Features\FeatureFlagTest). Forgetting the cache forces re-resolution against this test's
     *      rows (fixes the ~130 parallel permission failures and the FeatureFlag toggle).
     *
     *   3. Queue. The tests are written for a synchronous queue (so an event -> job -> consumer chain
     *      completes inline and can be asserted), but the ambient environment does not reliably
     *      resolve QUEUE_CONNECTION to sync. Pinning queue.default here guarantees the delivery jobs
     *      run in-test rather than being pushed to a Redis queue no worker drains (fixes
     *      Notifications\EventDeliveryTest and Live\SessionReminderDeliveryTest). Queue::fake() and
     *      the config-only Queue\AsyncResilienceTest are unaffected — the former replaces the queue
     *      manager outright, the latter reads specific connection configs, never the default.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        Cache::flush();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
