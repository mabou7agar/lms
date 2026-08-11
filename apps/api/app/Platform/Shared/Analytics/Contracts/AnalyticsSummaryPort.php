<?php

declare(strict_types=1);

namespace App\Platform\Shared\Analytics\Contracts;

use App\Platform\Shared\Analytics\Data\AnalyticsSummary;

/**
 * Shared read port exposing an AGGREGATE analytics KPI summary for the CURRENT tenant scope.
 *
 * The whole reason this lives in Shared is boundaries: the Admin AI Analytics Assistant (Platform/AI)
 * must ground its answers in real analytics figures, but a Platform module may not import the
 * Analytics context (Deptrac: AI -> Shared + IdentityContracts only). Analytics implements this port
 * against its metric_snapshots read model; AI depends solely on this interface + the returned Shared
 * {@see AnalyticsSummary} DTO.
 *
 * Tenant scope is EXPLICIT — the caller passes the organization to confine to (or null for a
 * platform-wide super_admin view), so scoping never depends on ambient request state. The summary
 * carries ONLY organization-level aggregate numbers — never a row about an individual learner.
 *
 * Money gating is the caller's decision, passed in: with $includeMoney = false the summary OMITS every
 * currency-denominated metric entirely, so an administrator without the revenue permission can never
 * be handed — or have the assistant cite — a money figure.
 */
interface AnalyticsSummaryPort
{
    /**
     * Aggregate KPI summary confined to the given organization.
     *
     * @param  bool  $includeMoney  whether the caller is permitted currency-denominated metrics;
     *                              when false, revenue/order metrics are omitted from the summary
     * @param  int|null  $organizationId  the organization to confine to (global + its own buckets);
     *                                    null yields the platform-wide view (super_admin)
     */
    public function summarize(bool $includeMoney, ?int $organizationId): AnalyticsSummary;
}
