<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Analytics\Models\ReportDefinition;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);
});

/**
 * The KPI, dashboard and report-definition endpoints shipped with no authorization beyond
 * `auth:sanctum`, so any authenticated user could read platform-wide figures including revenue.
 *
 * Two boundaries are pinned here, and they are different things:
 *   PERMISSION — `analytics.view` decides whether a caller may reach the analytics surface at all.
 *   SCOPE      — these endpoints are platform-wide. metric_snapshots carry no course dimension, so
 *                a query cannot be narrowed to the caller's own courses. An instructor is therefore
 *                refused even while holding the permission, because the alternative is showing them
 *                another instructor's numbers.
 */
function analyticsUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName($role, 'web'));

    return $user;
}

const ANALYTICS_READS = [
    '/api/v1/analytics/kpis?metrics[]=enrollments',
    '/api/v1/reports',
    '/api/v1/dashboards',
];

it('refuses an unauthenticated caller', function () {
    $this->getJson('/api/v1/analytics/kpis?metrics[]=enrollments')->assertUnauthorized();
});

it('refuses a student every analytics read', function (string $uri) {
    $this->actingAs(analyticsUser('student'), 'sanctum')->getJson($uri)->assertForbidden();
})->with(ANALYTICS_READS);

it('refuses an authenticated user with no role at all', function (string $uri) {
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson($uri)->assertForbidden();
})->with(ANALYTICS_READS);

it('refuses an instructor who does not hold analytics.view', function (string $uri) {
    SpatieRole::findByName('instructor', 'web')
        ->revokePermissionTo(AnalyticsPermission::ViewAnalytics->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $instructor = analyticsUser('instructor');
    expect($instructor->hasPermission(AnalyticsPermission::ViewAnalytics->value))->toBeFalse();

    // The generic refusal: no permission, so no analytics surface at all. Asserted on the raw body
    // rather than a JSON path, so the test pins the message and not the envelope's shape.
    $body = $this->actingAs($instructor, 'sanctum')->getJson($uri)
        ->assertForbidden()
        ->getContent();

    expect($body)->toContain('Analytics access required.');
})->with(ANALYTICS_READS);

it('refuses an instructor WITH analytics.view on scope, not on permission', function () {
    $instructor = analyticsUser('instructor');

    expect($instructor->hasPermission(AnalyticsPermission::ViewAnalytics->value))->toBeTrue();

    // A different refusal, and the distinction matters: the permission is held, but these figures
    // are platform-wide and cannot be narrowed to this instructor's courses. Returning them would
    // be a cross-instructor leak.
    $body = $this->actingAs($instructor, 'sanctum')
        ->getJson('/api/v1/analytics/kpis?metrics[]=enrollments')
        ->assertForbidden()
        ->getContent();

    expect($body)->toContain('platform-wide');
});

it('allows an admin every analytics read', function (string $uri) {
    $this->actingAs(analyticsUser('admin'), 'sanctum')->getJson($uri)->assertOk();
})->with(ANALYTICS_READS);

it('allows a super_admin without an explicit permission grant', function () {
    $this->actingAs(analyticsUser('super_admin'), 'sanctum')
        ->getJson('/api/v1/analytics/kpis?metrics[]=revenue')
        ->assertOk()
        ->assertJsonPath('data.kpis.0.metric', 'revenue');
});

it('lets an admin read money metrics', function () {
    $this->actingAs(analyticsUser('admin'), 'sanctum')
        ->getJson('/api/v1/analytics/kpis?metrics[]=enrollments&metrics[]=revenue')
        ->assertOk()
        ->assertJsonPath('data.kpis.0.metric', 'enrollments')
        ->assertJsonPath('data.kpis.1.metric', 'revenue');
});

it('grants instructors the analytics permission but never the revenue one', function () {
    $instructor = analyticsUser('instructor');

    // Revenue stays with administrators regardless of how the scope question is resolved later.
    expect($instructor->hasPermission(AnalyticsPermission::ViewAnalytics->value))->toBeTrue()
        ->and($instructor->hasPermission(AnalyticsPermission::ViewRevenue->value))->toBeFalse();
});

it('checks permissions under the sanctum guard, where $user->can() does not', function () {
    $admin = analyticsUser('admin');

    // Regression guard for the reason this codebase gates on roles in so many places: can()
    // resolves the request's guard, finds no permission registered under it, and answers false
    // for a user who genuinely holds the permission. hasPermission() pins the web guard.
    expect($admin->hasPermission(AnalyticsPermission::ViewAnalytics->value))->toBeTrue();
});

it('refuses to run a money-bearing report for a non-administrator', function () {
    $instructor = analyticsUser('instructor');
    $report = ReportDefinition::factory()->create(['metric_keys' => ['enrollments', 'revenue']]);

    $this->actingAs($instructor, 'sanctum')
        ->postJson('/api/v1/reports/run', ['report' => $report->public_id])
        ->assertForbidden();
});

it('runs a money-bearing report for an admin', function () {
    $report = ReportDefinition::factory()->create(['metric_keys' => ['revenue']]);

    $this->actingAs(analyticsUser('admin'), 'sanctum')
        ->postJson('/api/v1/reports/run', ['report' => $report->public_id])
        ->assertOk();
});

it('keeps the admin-gated insight reports closed to instructors', function () {
    $this->actingAs(analyticsUser('instructor'), 'sanctum')
        ->getJson('/api/v1/reports/insights/catalog')
        ->assertForbidden();
});
