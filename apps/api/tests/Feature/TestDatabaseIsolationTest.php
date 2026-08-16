<?php

use Illuminate\Support\Facades\DB;

/**
 * The suite uses RefreshDatabase, which drops and re-migrates every table between tests. If the
 * connection ever resolves to the development database, a test run destroys real data instead of
 * failing. phpunit.xml pins DB_DATABASE and TestCase::guardTestDatabase() aborts before the first
 * migration, but both are easy to regress silently — this asserts the outcome from inside a real
 * PHPUnit run, which is the only place the phpunit.xml override is actually exercised.
 */
it('runs against an isolated test database, never the development one', function (): void {
    $database = DB::selectOne('select current_database() as name')->name;

    expect($database)->not->toBe('helbaron');
    // `_test` for a serial run, `_test_{token}` for each `artisan test --parallel` worker.
    expect($database)->toMatch('/_test(_\d+)?$/');
});
