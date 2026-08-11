<?php

namespace App\Platform\AI\Database\Factories;

use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Models\AiUsage;
use App\Platform\Shared\Helpers\Uuid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    protected $model = AiUsage::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'user_id' => null,
            'feature' => AiFeature::Tutor,
            'provider' => AiProvider::Fake,
            'model' => 'fake-chat-v1',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'estimated_cost_micros' => 0,
            'request_id' => Uuid::v7(),
            'prompt_key' => null,
            'prompt_version' => null,
            'created_at' => now(),
        ];
    }
}
