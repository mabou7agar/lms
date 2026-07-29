<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Analytics\Models\ExportJob;
use App\Contexts\Analytics\Models\ReportDefinition;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);
});

/**
 * Analytics export authorization.
 *
 * `store` shipped with no authorization beyond `auth:sanctum`, so any authenticated user — a
 * learner included — could queue an export of a report they had no right to read, and then fetch
 * it. Gated on `analytics.export`, which existed and was seeded but had never been enforced.
 *
 * Export is a separate permission from viewing because it produces a durable artifact that leaves
 * the application and can be forwarded; the two capabilities are independently grantable.
 */
function exportUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName($role, 'web'));

    return $user;
}

function exportPayload(): array
{
    $report = ReportDefinition::factory()->create(['metric_keys' => ['enrollments']]);

    return ['report' => $report->public_id, 'format' => 'csv'];
}

it('lets an admin queue an export', function () {
    $this->actingAs(exportUser('admin'), 'sanctum')
        ->postJson('/api/v1/analytics/exports', exportPayload())
        ->assertCreated();
});

it('lets a super_admin queue an export without an explicit grant', function () {
    $this->actingAs(exportUser('super_admin'), 'sanctum')
        ->postJson('/api/v1/analytics/exports', exportPayload())
        ->assertCreated();
});

it('denies a student', function () {
    $this->actingAs(exportUser('student'), 'sanctum')
        ->postJson('/api/v1/analytics/exports', exportPayload())
        ->assertForbidden();
});

it('denies an instructor, who holds analytics.view but not analytics.export', function () {
    $instructor = exportUser('instructor');

    // The two permissions are deliberately separate: reading a figure is not the same capability
    // as producing a downloadable copy of it.
    expect($instructor->hasPermission(AnalyticsPermission::ViewAnalytics->value))->toBeTrue()
        ->and($instructor->hasPermission(AnalyticsPermission::ExportAnalytics->value))->toBeFalse();

    $this->actingAs($instructor, 'sanctum')
        ->postJson('/api/v1/analytics/exports', exportPayload())
        ->assertForbidden();
});

it('denies a user with no role at all', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/v1/analytics/exports', exportPayload())
        ->assertForbidden();
});

it('denies an unauthenticated caller', function () {
    $this->postJson('/api/v1/analytics/exports', exportPayload())->assertUnauthorized();
});

it('refuses before touching the report, so a denial leaks nothing about it', function () {
    // A bogus report id from an unauthorized caller must answer 403, not 404 — otherwise the
    // endpoint becomes an oracle for which report ids exist.
    $this->actingAs(exportUser('student'), 'sanctum')
        ->postJson('/api/v1/analytics/exports', ['report' => 'does-not-exist', 'format' => 'csv'])
        ->assertForbidden();
});

it('keeps one user from reading another user export', function () {
    $owner = exportUser('admin');
    $other = exportUser('admin');

    $created = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/analytics/exports', exportPayload())
        ->assertCreated()
        ->json('data.id');

    // Horizontal escalation: both are admins, so role alone would let this through. ExportJobPolicy
    // is owner-scoped, and holding the export permission does not confer access to someone else's
    // artifact.
    $this->actingAs($other, 'sanctum')
        ->getJson("/api/v1/analytics/exports/{$created}")
        ->assertForbidden();

    $this->actingAs($owner, 'sanctum')
        ->getJson("/api/v1/analytics/exports/{$created}")
        ->assertOk();
});

it('denies a student reading an export belonging to an admin', function () {
    $owner = exportUser('admin');
    $created = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/analytics/exports', exportPayload())
        ->assertCreated()
        ->json('data.id');

    $this->actingAs(exportUser('student'), 'sanctum')
        ->getJson("/api/v1/analytics/exports/{$created}")
        ->assertForbidden();
});

it('attributes the queued job to the caller, not to a supplied id', function () {
    $admin = exportUser('admin');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/analytics/exports', exportPayload())
        ->assertCreated();

    // Ownership comes from the authenticated principal; there is no client-supplied owner to forge.
    expect(ExportJob::query()->latest('id')->first()?->user_id)->toBe($admin->id);
});
