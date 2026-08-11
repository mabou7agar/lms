<?php

declare(strict_types=1);

namespace App\Platform\Integration\Database\Factories;

use App\Platform\Integration\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Endpoint '.$this->faker->unique()->numberBetween(1, 999999),
            'description' => null,
            // A non-resolvable .test host so the SSRF guard's DNS check is a no-op and no test hits a network.
            'url' => 'https://hooks.example.test/'.$this->faker->uuid(),
            'secret' => bin2hex(random_bytes(32)),
            'event_types' => ['course.completed'],
            'active' => true,
            'consecutive_failures' => 0,
            'disabled_at' => null,
            'created_by' => null,
        ];
    }

    /** @param  array<int, string>  $events */
    public function subscribedTo(array $events): static
    {
        return $this->state(fn (): array => ['event_types' => $events]);
    }

    public function organization(?int $organizationId): static
    {
        return $this->state(fn (): array => ['organization_id' => $organizationId]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false, 'disabled_at' => now()]);
    }
}
