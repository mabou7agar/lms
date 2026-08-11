<?php

namespace App\Platform\Identity\Services;

use App\Platform\Identity\Enums\ApiScope;

/**
 * Hand-built, deterministic OpenAPI 3.1 document for the public developer API surface. No external
 * generator package is used, so the output is stable and reviewable. The scope catalog is pulled
 * straight from {@see ApiScope} — the single source of truth — so the document can never drift from
 * the abilities actually enforced on the routes.
 */
final class OpenApiSpecGenerator
{
    /**
     * @var array<string, array{scope: ApiScope, summary: string}>
     */
    private const ENDPOINTS = [
        '/v1/developer/account' => ['scope' => ApiScope::AccountRead, 'summary' => 'Authenticated account profile.'],
        '/v1/developer/organization' => ['scope' => ApiScope::OrgRead, 'summary' => 'Organization and subscription summary.'],
        '/v1/developer/seats' => ['scope' => ApiScope::SeatsRead, 'summary' => 'Subscription seat capacity.'],
        '/v1/developer/usage' => ['scope' => ApiScope::UsageRead, 'summary' => 'Seat-utilisation summary.'],
    ];

    /** @return array<string, mixed> */
    public function generate(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'CoreLMS Public Developer API',
                'version' => 'v1',
                'description' => 'Read-only public API for organization and account data, authenticated '
                    .'with scoped bearer tokens (developer API keys). Each key carries a subset of the '
                    .'scopes listed under components.x-api-scopes.',
            ],
            'servers' => [
                ['url' => '/api', 'description' => 'API base path'],
            ],
            'paths' => $this->paths(),
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum personal access token',
                        'description' => 'Scoped developer API key issued via POST /api/v1/api-keys. Send it '
                            .'as `Authorization: Bearer <token>`. A request whose token lacks the scope '
                            .'required by the endpoint is rejected with 403.',
                    ],
                ],
                'x-api-scopes' => ApiScope::catalog(),
                'responses' => [
                    'Unauthorized' => ['description' => 'Missing, revoked, or expired token.'],
                    'Forbidden' => ['description' => 'The token does not carry the scope required by this endpoint.'],
                ],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function paths(): array
    {
        $paths = [];

        foreach (self::ENDPOINTS as $path => $definition) {
            $scope = $definition['scope'];

            $paths[$path] = [
                'get' => [
                    'summary' => $definition['summary'],
                    'description' => $scope->description(),
                    'operationId' => 'get_'.str_replace(['/', ':'], ['_', '_'], ltrim($path, '/')),
                    'security' => [
                        ['bearerAuth' => [$scope->value]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Success.'],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                    ],
                ],
            ];
        }

        return $paths;
    }
}
