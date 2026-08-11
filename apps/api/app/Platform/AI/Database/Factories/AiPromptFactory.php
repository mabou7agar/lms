<?php

namespace App\Platform\AI\Database\Factories;

use App\Platform\AI\Models\AiPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPrompt>
 */
class AiPromptFactory extends Factory
{
    protected $model = AiPrompt::class;

    public function definition(): array
    {
        return [
            'key' => 'tutor.explain',
            'purpose' => 'Explain a concept to a learner',
            'version' => 1,
            'system_prompt' => 'You are a helpful tutor.',
            'user_template' => 'Explain {{ topic }} to a {{ level }} learner.',
            'variables' => ['topic', 'level'],
            'model_preference' => null,
            'locale' => 'en',
            'active' => true,
            'created_by' => null,
        ];
    }
}
