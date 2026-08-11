<?php

declare(strict_types=1);

namespace App\Platform\Integration\Database\Factories;

use App\Platform\Integration\Enums\DeliveryStatus;
use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Integration\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event_type' => 'course.completed',
            'event_id' => 'course.completed:'.$this->faker->uuid(),
            'payload' => ['hello' => 'world'],
            'status' => DeliveryStatus::Pending->value,
            'attempts' => 0,
        ];
    }

    public function forEndpoint(WebhookEndpoint $endpoint): static
    {
        return $this->state(fn (): array => [
            'webhook_endpoint_id' => $endpoint->id,
            'organization_id' => $endpoint->organization_id,
        ]);
    }
}
