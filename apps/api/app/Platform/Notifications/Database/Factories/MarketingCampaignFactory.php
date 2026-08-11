<?php

namespace App\Platform\Notifications\Database\Factories;

use App\Platform\Notifications\Enums\CampaignStatus;
use App\Platform\Notifications\Models\MarketingCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingCampaign>
 */
class MarketingCampaignFactory extends Factory
{
    protected $model = MarketingCampaign::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Campaign '.$this->faker->unique()->numberBetween(1, 999999),
            'status' => CampaignStatus::Active->value,
            'audience_type' => 'lead',
            'audience_filter' => null,
        ];
    }

    public function organization(?int $organizationId): static
    {
        return $this->state(fn (): array => ['organization_id' => $organizationId]);
    }

    /** @param array<string, scalar> $filter */
    public function segment(string $audienceType, array $filter): static
    {
        return $this->state(fn (): array => ['audience_type' => $audienceType, 'audience_filter' => $filter]);
    }
}
