<?php

use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('requires authentication for the privacy surface', function () {
    $this->getJson('/api/v1/privacy/consents')->assertStatus(401);
    $this->getJson('/api/v1/privacy/export')->assertStatus(401);
});

it('records then reads consent for the authenticated user', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/privacy/consents', ['purpose' => 'marketing', 'granted' => true])
        ->assertOk()->assertJsonPath('data.consents.marketing', true);

    $this->getJson('/api/v1/privacy/consents')
        ->assertOk()->assertJsonPath('data.consents.marketing', true);

    $this->postJson('/api/v1/privacy/consents', ['purpose' => 'marketing', 'granted' => false])
        ->assertOk()->assertJsonPath('data.consents.marketing', false);
});

it('rejects an unknown consent purpose', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/privacy/consents', ['purpose' => 'nope', 'granted' => true])
        ->assertStatus(422);
});

it('exports the identity data envelope for the user', function () {
    Sanctum::actingAs(User::factory()->create(['email' => 'me@ex.test']));

    $this->getJson('/api/v1/privacy/export')
        ->assertOk()
        ->assertJsonStructure(['data' => ['account', 'profile', 'consents', 'devices', 'social_accounts', 'data_requests']])
        ->assertJsonPath('data.account.email', 'me@ex.test');
});

it('submits and lists data-subject requests', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/privacy/data-requests', ['type' => 'access'])
        ->assertStatus(201)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.type', 'access');

    $this->getJson('/api/v1/privacy/data-requests')
        ->assertOk()->assertJsonPath('data.0.type', 'access');
});

it('rejects an unknown data-request type', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/privacy/data-requests', ['type' => 'invalid'])->assertStatus(422);
});
