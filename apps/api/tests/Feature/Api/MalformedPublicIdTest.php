<?php

declare(strict_types=1);

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * A junk identifier in a URL is a 404, never a 500.
 *
 * `public_id` is a Postgres `uuid` column, so comparing it to "not-a-uuid" raises SQLSTATE 22P02 and
 * the request dies as a server error. That is wrong twice over: the caller is told the server broke
 * when in fact they asked for something that cannot exist, and every crawler, scanner and mistyped
 * link books an error-tracker alert. Implicit route-model binding has always guarded this; the
 * lookups that read `public_id` directly did not.
 *
 * These probes are deliberately spread across contexts. The fix is only worth having if it holds at
 * every door, and a single guarded endpoint proves nothing about the next one.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

const JUNK = 'not-a-uuid';

it('answers 404 for a malformed id on the public catalogue', function (string $path): void {
    $this->getJson($path)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'HTTP_NOT_FOUND');
})->with([
    '/api/v1/courses/'.JUNK,
    '/api/v1/products/'.JUNK,
]);

it('answers 404 for a malformed id on an authenticated learner route', function (string $path): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson($path)->assertNotFound();
})->with([
    '/api/v1/courses/'.JUNK.'/questions',
    '/api/v1/courses/'.JUNK.'/curriculum',
    '/api/v1/courses/'.JUNK.'/resources',
    '/api/v1/orders/'.JUNK,
]);

it('answers 404 rather than a server error when a malformed id is posted', function (): void {
    Sanctum::actingAs(User::factory()->create());

    // The cart looks the product up by hand rather than through route-model binding.
    $this->postJson('/api/v1/cart', ['product' => JUNK])->assertNotFound();
});

it('never leaks a database error for a malformed id', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/v1/courses/'.JUNK.'/questions');

    expect($response->status())->toBeLessThan(500)
        ->and($response->getContent())->not->toContain('SQLSTATE');
});
