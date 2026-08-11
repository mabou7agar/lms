<?php

use App\Domains\Crm\Database\Seeders\CrmSeeder;
use App\Domains\Crm\Models\Lead;
use App\Domains\Crm\Models\Opportunity;
use App\Domains\Crm\Models\Stage;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CrmSeeder::class);
});

function actingAsSalesAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('creates an opportunity placed at the first pipeline stage, logging the timeline', function () {
    actingAsSalesAdmin();

    $res = $this->postJson('/api/v1/opportunities', ['name' => 'Enterprise rollout', 'amount_minor' => 500000, 'currency' => 'USD'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.stage', 'New');

    $opportunity = Opportunity::where('public_id', $res->json('data.id'))->firstOrFail();
    expect($opportunity->stage?->name)->toBe('New')
        ->and($opportunity->activities()->count())->toBe(1);
});

it('moves an opportunity to a won stage, closing the deal', function () {
    actingAsSalesAdmin();

    $id = $this->postJson('/api/v1/opportunities', ['name' => 'Deal'])->json('data.id');
    $won = Stage::where('is_won', true)->firstOrFail();

    $this->postJson("/api/v1/opportunities/{$id}/stage", ['stage' => $won->public_id])
        ->assertOk()
        ->assertJsonPath('data.status', 'won')
        ->assertJsonPath('data.probability', 100);

    $opportunity = Opportunity::where('public_id', $id)->firstOrFail();
    expect($opportunity->won_at)->not->toBeNull()->and($opportunity->closed_at)->not->toBeNull();
});

it('creates an opportunity from a lead, inheriting its value', function () {
    actingAsSalesAdmin();

    $lead = Lead::factory()->create(['value_minor' => 250000, 'currency' => 'USD']);

    $res = $this->postJson('/api/v1/opportunities', ['name' => 'From lead', 'lead' => $lead->public_id])
        ->assertCreated()
        ->assertJsonPath('data.amount_minor', 250000);

    $opportunity = Opportunity::where('public_id', $res->json('data.id'))->firstOrFail();
    expect($opportunity->lead_id)->toBe($lead->id);
});

it('denies opportunity creation to a non-sales user', function () {
    $user = User::factory()->create(); // no CRM permissions
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/opportunities', ['name' => 'Nope'])->assertForbidden();
});

it('requires authentication for the pipeline endpoints', function () {
    $this->postJson('/api/v1/opportunities', ['name' => 'Nope'])->assertUnauthorized();
});
