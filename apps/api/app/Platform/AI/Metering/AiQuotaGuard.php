<?php

declare(strict_types=1);

namespace App\Platform\AI\Metering;

use App\Platform\AI\Exceptions\AiQuotaExceededException;
use App\Platform\AI\Models\AiUsage;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Enforces config-driven, tenant-scoped usage limits BEFORE a provider is ever called, so an
 * over-quota request costs nothing. Four independent ceilings: per-request, per-user/day,
 * per-org/month, and global/month. A limit of 0 (or null) means unlimited.
 *
 * Counting runs under an explicit tenancy bypass and filters by organization_id / user_id directly,
 * so a tenant's spend is measured against ITS OWN history regardless of the ambient tenant context
 * — one org can never consume another's budget.
 */
final class AiQuotaGuard
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function assertWithinLimits(int $requestedTokens, ?int $organizationId, ?int $userId): void
    {
        /** @var array<string, mixed> $limits */
        $limits = (array) config('ai.limits', []);

        $perRequest = (int) ($limits['max_tokens_per_request'] ?? 0);
        if ($perRequest > 0 && $requestedTokens > $perRequest) {
            throw new AiQuotaExceededException('request', $perRequest, $requestedTokens);
        }

        $perUserDay = (int) ($limits['per_user_daily_tokens'] ?? 0);
        if ($perUserDay > 0 && $userId !== null) {
            $used = $this->usedTokens(fn (Builder $q) => $q
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->startOfDay()));
            if ($used + $requestedTokens > $perUserDay) {
                throw new AiQuotaExceededException('user_daily', $perUserDay, $used + $requestedTokens);
            }
        }

        $perOrgMonth = (int) ($limits['per_org_monthly_tokens'] ?? 0);
        if ($perOrgMonth > 0 && $organizationId !== null) {
            $used = $this->usedTokens(fn (Builder $q) => $q
                ->where('organization_id', $organizationId)
                ->where('created_at', '>=', now()->startOfMonth()));
            if ($used + $requestedTokens > $perOrgMonth) {
                throw new AiQuotaExceededException('org_monthly', $perOrgMonth, $used + $requestedTokens);
            }
        }

        $globalMonth = (int) ($limits['global_monthly_tokens'] ?? 0);
        if ($globalMonth > 0) {
            $used = $this->usedTokens(fn (Builder $q) => $q
                ->where('created_at', '>=', now()->startOfMonth()));
            if ($used + $requestedTokens > $globalMonth) {
                throw new AiQuotaExceededException('global_monthly', $globalMonth, $used + $requestedTokens);
            }
        }
    }

    /**
     * Total (input + output) tokens matching a filter, counted across ALL tenants (explicit bypass).
     *
     * @param  callable(Builder<AiUsage>): mixed  $filter
     */
    private function usedTokens(callable $filter): int
    {
        return $this->tenant->runWithoutTenancy(function () use ($filter): int {
            /** @var Builder<AiUsage> $query */
            $query = AiUsage::query();
            $filter($query);

            return (int) $query->sum('input_tokens') + (int) $query->sum('output_tokens');
        });
    }
}
