<?php

namespace App\Platform\Identity\Enums;

/**
 * Catalog of read-only PUBLIC API abilities (Sanctum token abilities) grantable to developer API
 * keys. This enum is the SINGLE SOURCE OF TRUTH consumed by:
 *   - key-creation validation (only catalog scopes may be granted),
 *   - the Sanctum ability check applied to each developer read endpoint,
 *   - the served OpenAPI document (scope list + per-endpoint security).
 *
 * Scopes intentionally cover ONLY org/account data reachable through Identity + the Shared
 * OrganizationSubscriptionPort. Course/learner domain data is deliberately NOT exposed here, to
 * avoid cross-layer coupling.
 */
enum ApiScope: string
{
    case AccountRead = 'account:read';
    case OrgRead = 'org:read';
    case SeatsRead = 'seats:read';
    case UsageRead = 'usage:read';

    /** Human description — feeds the OpenAPI scope catalog. */
    public function description(): string
    {
        return match ($this) {
            self::AccountRead => 'Read the authenticated account profile (name, email, locale, organization).',
            self::OrgRead => 'Read the summary of the organization the key belongs to.',
            self::SeatsRead => 'Read the organization subscription seat capacity (purchased / used / available).',
            self::UsageRead => 'Read the organization seat-utilisation summary.',
        };
    }

    /**
     * Identity permission the key CREATOR must already hold to be allowed to grant this scope, or
     * null when any authenticated account may grant it. This enforces the invariant that a key can
     * never exceed the creator's own permissions.
     */
    public function requiredPermission(): ?string
    {
        return match ($this) {
            self::AccountRead => null,
            self::OrgRead, self::SeatsRead, self::UsageRead => Permission::ViewUsers->value,
        };
    }

    /**
     * The scope catalog as key => description. Single source consumed by the OpenAPI generator.
     *
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::cases() as $scope) {
            $catalog[$scope->value] = $scope->description();
        }

        return $catalog;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
