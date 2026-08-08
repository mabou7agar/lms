<?php

namespace App\Contexts\Commerce\Exceptions;

/**
 * Raised when the request's active tenant is not authorized to read or mutate an ORGANIZATION
 * subscription (or its seat pool/assignments) belonging to a different organization.
 *
 * This is the explicit T1 tenant boundary for `subscriptions` — a table that deliberately carries NO
 * blanket tenant global scope (it holds both individual user subs and org subs; see
 * T1_TENANT_OWNERSHIP_MATRIX §4). Org-sub isolation is therefore enforced by comparing the target
 * organization against the request's resolved tenant, which the kernel derives ONLY from the
 * authenticated user's organization_id (never from client input). A mismatch lands here as a 403.
 */
class OrganizationSubscriptionAccessDeniedException extends CommerceException
{
    protected string $errorCode = 'ORGANIZATION_SUBSCRIPTION_FORBIDDEN';

    protected int $status = 403;

    public static function forOrganization(int $organizationId): self
    {
        return new self(
            "The active tenant is not authorized to access organization [{$organizationId}]'s subscription.",
            ['organization_id' => $organizationId],
        );
    }
}
