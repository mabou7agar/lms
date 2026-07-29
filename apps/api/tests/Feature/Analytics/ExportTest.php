<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Enums\ExportStatus;
use App\Contexts\Analytics\Export\ExportWriterManager;
use App\Contexts\Analytics\Jobs\ProcessExportJob;
use App\Contexts\Analytics\Models\ExportJob;
use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Contexts\Analytics\Models\ReportDefinition;
use App\Contexts\Analytics\Services\ExportService;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);
});

it('queues an async export and produces a downloadable file', function () {
    Storage::fake('local');
    MetricSnapshot::factory()->create(['metric_key' => 'enrollments', 'value' => 9]);
    $report = ReportDefinition::factory()->create(['metric_keys' => ['enrollments']]);

    // Queuing an export now requires `analytics.export`, seeded to admin only. This test covers the
    // export MECHANICS; the authorization boundary itself is pinned in ExportAuthorizationTest.
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName('admin', 'web'));
    Sanctum::actingAs($user);

    $res = $this->postJson('/api/v1/analytics/exports', ['report' => $report->public_id, 'format' => 'csv'])
        ->assertCreated()->assertJsonPath('data.status', 'queued');

    $job = ExportJob::where('public_id', $res->json('data.id'))->firstOrFail();

    // Run the async job (afterCommit dispatch doesn't fire inside the test transaction).
    (new ProcessExportJob($job->id))->handle(app(ExportService::class), app(ExportWriterManager::class));

    $job->refresh();
    expect($job->status)->toBe(ExportStatus::Completed)
        ->and($job->row_count)->toBe(1)
        ->and(Storage::disk('local')->exists($job->file_path))->toBeTrue();

    $show = $this->getJson("/api/v1/analytics/exports/{$job->public_id}")->assertOk();
    expect($show->json('data.download_url'))->toContain('signature=');
    expect($show->getContent())->not->toContain('file_path');
});
