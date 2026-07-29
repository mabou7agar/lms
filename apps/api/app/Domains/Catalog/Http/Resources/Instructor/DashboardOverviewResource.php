<?php

namespace App\Domains\Catalog\Http\Resources\Instructor;

use App\Platform\Shared\Analytics\Data\MetricValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The instructor dashboard overview.
 *
 * Every metric is emitted through the same `{value, available, reason?}` envelope, including the
 * ones that ARE available. A uniform shape means the client renders unavailability by reading a
 * flag rather than by special-casing particular metric names — so a metric that becomes available
 * later needs no frontend change, and one that becomes unavailable cannot silently render as 0.
 *
 * @property array<string, MetricValue> $resource
 */
class DashboardOverviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_map(
            static fn (MetricValue $metric): array => $metric->toArray(),
            $this->resource,
        );
    }
}
