<?php

namespace App\Contexts\Analytics\Database\Factories;

use App\Contexts\Analytics\Models\MetricSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricSnapshot>
 */
class MetricSnapshotFactory extends Factory
{
    protected $model = MetricSnapshot::class;

    public function definition(): array
    {
        return [
            // NULL = global/platform metric by default (matches every pre-tenancy row).
            'organization_id' => null,
            'metric_key' => 'enrollments',
            'granularity' => 'daily',
            'period' => now()->toDateString(),
            'dimension_key' => '',
            'dimension_value' => '',
            'value' => fake()->numberBetween(1, 100),
        ];
    }

    /** An organization-owned metric bucket (adversarial tenancy tests). */
    public function forOrganization(int $organizationId): static
    {
        return $this->state(fn (): array => ['organization_id' => $organizationId]);
    }
}
