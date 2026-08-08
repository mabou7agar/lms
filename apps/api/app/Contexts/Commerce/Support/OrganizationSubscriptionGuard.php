<?php

namespace App\Contexts\Commerce\Support;

use App\Contexts\Commerce\Exceptions\OrganizationSubscriptionAccessDeniedException;
use App\Contexts\Commerce\Models\Subscription;
use App\Platform\Shared\Tenancy\Contracts\CurrentTenantProvider;

/**
 * Tenant-boundary guard for ORGANIZATION subscription + seat operations (T1).
 *
 * Why this exists instead of a global scope: `subscriptions` holds BOTH individual user subscriptions
 * (user_id, organization_id NULL) and organization subscriptions (organization_id). Attaching a
 * blanket strict TenantScope to the table would hide an org employee's individual subscription from
 * them — so the T1 matrix (§4) forbids it. Org-sub isolation is instead enforced EXPLICITLY here: the
 * target organization must equal the request's active tenant.
 *
 * The active tenant is derived by the kernel ONLY from the authenticated user's organization_id
 * (RequestTenantResolver) and NEVER from client input. Consequences:
 *   - an org1-authenticated caller (active tenant = org1) is refused any read or mutation aimed at
 *     org2's subscription/seats — read, cancel, change-plan, resize, assign/unassign;
 *   - a forged `organization_id` in a request payload is inert, because the value compared here is the
 *     resolved tenant, not the client-supplied one.
 *
 * Backward-compat (critical): when NO tenant is resolved — individual/no-org callers, the existing
 * null-org test suite, system/console/queue contexts, and platform admins whose organization_id is
 * null — the guard is a no-op, exactly like the strict CRM scope on seat_pools. Enforcement engages
 * only once a concrete tenant is active. Individual subscriptions are never gated here.
 */
final class OrganizationSubscriptionGuard
{
    public function __construct(
        private readonly CurrentTenantProvider $tenant,
    ) {}

    /**
     * Assert the active tenant (when one is resolved) may operate on the given organization. No-op
     * when no tenant is active. Throws a 403 domain exception on a cross-tenant attempt.
     */
    public function authorizeOrganization(int $organizationId): void
    {
        $active = $this->tenant->currentTenant();

        if ($active === null) {
            return; // null-tolerant: no resolved tenant → unscoped, mirroring the kernel scope
        }

        if ((string) $active->value !== (string) $organizationId) {
            throw OrganizationSubscriptionAccessDeniedException::forOrganization($organizationId);
        }
    }

    /**
     * Assert the active tenant may operate on the given subscription. INDIVIDUAL (user) subscriptions
     * carry no organization and are never gated — their access stays owner-based and unchanged. Only
     * organization subscriptions are tenant-boundary checked.
     */
    public function authorizeSubscription(Subscription $subscription): void
    {
        $organizationId = $subscription->organizationId();

        if ($organizationId === null) {
            return; // individual subscription: untouched by org tenancy
        }

        $this->authorizeOrganization($organizationId);
    }
}
