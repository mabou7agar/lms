<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Contexts\Analytics\Models\ReportDefinition;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    // AnalyticsSeeder creates the permission rows the gate now reads. Without it the admin role
    // exists but holds nothing, and every request here answers 403.
    $this->seed(AnalyticsSeeder::class);
});

/**
 * These endpoints used to be reachable by any authenticated user. They are now gated on the
 * `analytics.view` permission, which the admin role holds — see AnalyticsAuthorizationTest for the
 * boundary itself; this file is only asserting the report mechanics.
 */
function analyticsAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName('admin', 'web'));

    return $user;
}

it('runs a report from the read model and returns metric totals', function () {
    MetricSnapshot::factory()->create(['metric_key' => 'enrollments', 'period' => now()->toDateString(), 'value' => 7]);
    MetricSnapshot::factory()->create(['metric_key' => 'completions', 'period' => now()->toDateString(), 'value' => 3]);

    $report = ReportDefinition::factory()->create(['metric_keys' => ['enrollments', 'completions']]);

    Sanctum::actingAs(analyticsAdmin());

    $res = $this->postJson('/api/v1/reports/run', ['report' => $report->public_id])->assertOk();

    $rows = collect($res->json('data.result.rows'))->keyBy('metric');
    expect($rows['enrollments']['total'])->toBe(7)
        ->and($rows['completions']['total'])->toBe(3);
});

it('lists reports and shows one', function () {
    $report = ReportDefinition::factory()->create(['name' => 'My Report']);
    Sanctum::actingAs(analyticsAdmin());

    $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.0.name', 'My Report');
    $this->getJson("/api/v1/reports/{$report->public_id}")->assertOk()->assertJsonPath('data.id', $report->public_id);
});
