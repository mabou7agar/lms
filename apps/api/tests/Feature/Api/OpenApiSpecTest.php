<?php

use App\Platform\Identity\Enums\ApiScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('serves a valid OpenAPI 3.1 document at a public url', function () {
    $response = $this->getJson('/api/openapi.json')->assertOk();

    $spec = $response->json();
    expect($spec)->toBeArray();
    expect($spec['openapi'])->toBe('3.1.0');
    expect($spec['info']['title'])->toBeString()->not->toBe('');
});

it('declares the bearer scoped-token security scheme', function () {
    $spec = $this->getJson('/api/openapi.json')->assertOk()->json();

    $scheme = $spec['components']['securitySchemes']['bearerAuth'];
    expect($scheme['type'])->toBe('http');
    expect($scheme['scheme'])->toBe('bearer');
});

it('lists every catalog scope from the single source of truth', function () {
    $spec = $this->getJson('/api/openapi.json')->assertOk()->json();

    $documented = array_keys($spec['components']['x-api-scopes']);

    foreach (ApiScope::values() as $scope) {
        expect($documented)->toContain($scope);
        // Each scope carries a human description.
        expect($spec['components']['x-api-scopes'][$scope])->toBeString()->not->toBe('');
    }
});

it('documents every developer read endpoint with its required scope', function () {
    $spec = $this->getJson('/api/openapi.json')->assertOk()->json();

    expect($spec['paths'])->toHaveKeys([
        '/v1/developer/account',
        '/v1/developer/organization',
        '/v1/developer/seats',
        '/v1/developer/usage',
    ]);

    expect($spec['paths']['/v1/developer/organization']['get']['security'][0]['bearerAuth'])
        ->toContain('org:read');
});

it('exports the same document to a file via the artisan command', function () {
    $path = storage_path('app/testing/openapi-export.json');
    @unlink($path);

    Artisan::call('identity:openapi-export', ['--path' => $path]);

    expect(file_exists($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);
    expect($decoded['openapi'])->toBe('3.1.0');
    expect(array_keys($decoded['components']['x-api-scopes']))->toContain('account:read');

    @unlink($path);
});
