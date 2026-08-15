<?php

namespace App\Platform\Shared\Enterprise\Contracts;

/**
 * Cross-context capability check: is a user an ENTERPRISE MANAGER (org owner/admin, or a department/
 * team manager) in any organization they belong to? DECLARED here in Shared, IMPLEMENTED by the CRM
 * context (which owns organization membership + ManagerScope), CONSUMED by Identity so the profile
 * payload can carry a UI hint WITHOUT Identity importing a CRM model.
 *
 * This is ONLY a presentational hint for the manager-portal route guard. Authorization stays with
 * CRM's OrganizationMemberPolicy / ManagerScope (Gate::authorize('manageMembers', ...)) — this port
 * neither grants nor widens any capability.
 */
interface OrgManagerCheckPort
{
    /** Whether the user has enterprise manager authority in at least one of their organizations. */
    public function managesAnyOrganization(int $userId): bool;
}
