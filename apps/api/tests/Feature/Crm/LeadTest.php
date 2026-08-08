<?php

use App\Domains\Crm\Models\Lead;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    Sanctum::actingAs($admin);
});

it('creates a lead (logging a timeline activity) and lists/searches leads', function () {
    $res = $this->postJson('/api/v1/leads', ['name' => 'Jane Qzbuyerx', 'email' => 'jane@corp.com', 'source' => 'web'])
        ->assertCreated()->assertJsonPath('data.status', 'new');

    $lead = Lead::where('public_id', $res->json('data.id'))->firstOrFail();
    expect($lead->activities()->count())->toBe(1); // LeadCreated -> ActivityLogger

    Lead::factory()->create(['name' => 'Other Person']);

    // Search on a unique token so the assertion is deterministic: the broad lead search matches
    // name/email/company, and a faker-generated second lead can otherwise incidentally match a common
    // term like "Jane". "Qzbuyerx" only ever appears on the lead created above.
    $this->getJson('/api/v1/leads?q=Qzbuyerx')->assertOk()->assertJsonPath('meta.total', 1);
});
