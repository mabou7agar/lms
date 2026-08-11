<?php

use App\Domains\Crm\Models\CrmTask;
use App\Domains\Crm\Models\Lead;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('creates a typed task against a lead and logs it on the timeline', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    Sanctum::actingAs($admin);

    $lead = Lead::factory()->create();

    $res = $this->postJson('/api/v1/tasks', [
        'subject_type' => 'lead',
        'subject' => $lead->public_id,
        'title' => 'Call back the buyer',
        'type' => 'call',
        'priority' => 'high',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'call')
        ->assertJsonPath('data.status', 'open');

    expect($lead->tasks()->count())->toBe(1)
        ->and($lead->activities()->count())->toBe(1);

    $taskId = $res->json('data.id');
    $this->postJson("/api/v1/tasks/{$taskId}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'done');

    $task = CrmTask::where('public_id', $taskId)->firstOrFail();
    expect($task->completed_at)->not->toBeNull();
});

it('denies task creation to a non-sales user', function () {
    $lead = Lead::factory()->create();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/tasks', [
        'subject_type' => 'lead',
        'subject' => $lead->public_id,
        'title' => 'Nope',
    ])->assertForbidden();
});

it('requires authentication to create a task', function () {
    $lead = Lead::factory()->create();

    $this->postJson('/api/v1/tasks', [
        'subject_type' => 'lead',
        'subject' => $lead->public_id,
        'title' => 'Nope',
    ])->assertUnauthorized();
});
