<?php

use App\Domains\Crm\Database\Seeders\CrmSeeder;
use App\Domains\Crm\Models\Lead;
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

function salesAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('moves a lead to another stage of its pipeline, logging the change', function () {
    salesAdmin();

    $leadId = $this->postJson('/api/v1/leads', ['name' => 'Mo Client', 'email' => 'mo@corp.com'])->json('data.id');
    $qualified = Stage::where('name', 'Qualified')->firstOrFail();

    $this->postJson("/api/v1/leads/{$leadId}/stage", ['stage' => $qualified->public_id])
        ->assertOk()
        ->assertJsonPath('data.stage', 'Qualified');

    $lead = Lead::where('public_id', $leadId)->firstOrFail();
    expect($lead->stage_id)->toBe($qualified->id)
        ->and($lead->activities()->where('type', 'stage_change')->count())->toBe(1);
});

it('converts a lead into a contact and blocks double-conversion', function () {
    salesAdmin();

    $leadId = $this->postJson('/api/v1/leads', ['name' => 'Sara Client', 'email' => 'sara@corp.com'])->json('data.id');

    $this->postJson("/api/v1/leads/{$leadId}/convert")
        ->assertCreated()
        ->assertJsonPath('data.first_name', 'Sara');

    expect(Lead::where('public_id', $leadId)->firstOrFail()->status->value)->toBe('converted');

    // Second attempt is guarded (409 CRM_LEAD_ALREADY_CONVERTED).
    $this->postJson("/api/v1/leads/{$leadId}/convert")->assertStatus(409);
});

it('denies stage moves + conversion to a non-sales user', function () {
    $lead = Lead::factory()->create();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $stage = Stage::first();
    $this->postJson("/api/v1/leads/{$lead->public_id}/stage", ['stage' => $stage->public_id])->assertForbidden();
    $this->postJson("/api/v1/leads/{$lead->public_id}/convert")->assertForbidden();
});

it('requires authentication for lead pipeline actions', function () {
    $lead = Lead::factory()->create();

    $this->postJson("/api/v1/leads/{$lead->public_id}/convert")->assertUnauthorized();
});
