<?php

namespace App\Contexts\Analytics\Services;

use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Platform\Shared\Services\BaseService;
use App\Platform\Shared\Tenancy\Contracts\CurrentTenantProvider;
use Carbon\CarbonInterface;

/**
 * Maintains the metric_snapshots read model. Increments converge per
 * (metric, granularity, period, dimension) so the bucket is idempotent by key.
 *
 * T1 tenant dimension: the bucket key ALSO carries the active organization id (resolved from the
 * TenantContext, never from client input). An org employee's event lands in that org's own bucket;
 * a system/anonymous event (no resolved tenant) lands in the GLOBAL (organization_id NULL) bucket —
 * identical to the pre-tenancy behaviour. This keeps org1 and org2 activity in DISTINCT buckets so a
 * scoped read never blends them.
 */
class MetricRollupService extends BaseService
{
    public function increment(string $metricKey, int $amount = 1, ?CarbonInterface $when = null, string $dimensionKey = '', string $dimensionValue = ''): void
    {
        $period = ($when ?? now())->toDateString();

        // Resolved lazily here (not via a constructor arg) so `new MetricRollupService` keeps working.
        // NULL = no tenant resolved => global platform bucket.
        $organizationId = app(CurrentTenantProvider::class)->currentTenant()?->value;

        $this->transaction(function () use ($metricKey, $amount, $period, $dimensionKey, $dimensionValue, $organizationId): void {
            $snapshot = MetricSnapshot::firstOrCreate(
                [
                    'metric_key' => $metricKey,
                    'granularity' => 'daily',
                    'period' => $period,
                    'dimension_key' => $dimensionKey,
                    'dimension_value' => $dimensionValue,
                    'organization_id' => $organizationId,
                ],
                ['value' => 0],
            );

            $snapshot->increment('value', $amount);
        });
    }
}
