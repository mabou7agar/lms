<?php

use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('reports OIDC supported and SAML unsupported', function () {
    Sanctum::actingAs(User::factory()->create());

    $res = $this->getJson('/api/v1/sso/capabilities')->assertOk();

    expect($res->json('data.oidc.supported'))->toBeTrue()
        ->and($res->json('data.saml.supported'))->toBeFalse()
        ->and($res->json('data.saml.reason'))->toContain('XML-DSIG');
});

it('advertises only enabled real OIDC providers (never the fake seam)', function () {
    config(['sso.providers.google.enabled' => true, 'sso.providers.fake.enabled' => true]);

    Sanctum::actingAs(User::factory()->create());

    $providers = $this->getJson('/api/v1/sso/capabilities')->json('data.oidc.providers');

    expect($providers)->toContain('google')
        ->and($providers)->not->toContain('fake');
});

it('fails closed on a SAML ACS request without accepting any assertion', function () {
    // A posted SAMLResponse must NEVER be consumed — the endpoint returns 501 regardless.
    $this->postJson('/api/v1/sso/saml/acs', ['SAMLResponse' => base64_encode('<forged-assertion/>')])
        ->assertStatus(501)
        ->assertJsonPath('error.code', 'SSO_SAML_UNSUPPORTED');
});

it('reports SAML metadata as not supported', function () {
    $this->getJson('/api/v1/sso/saml/metadata')
        ->assertStatus(501)
        ->assertJsonPath('error.code', 'SSO_SAML_UNSUPPORTED');
});
