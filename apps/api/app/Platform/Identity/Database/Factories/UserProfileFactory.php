<?php

namespace App\Platform\Identity\Database\Factories;

use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserProfile>
 */
class UserProfileFactory extends Factory
{
    protected $model = UserProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'bio' => fake()->sentence(),
            'gender' => fake()->randomElement(['male', 'female', 'unspecified']),
        ];
    }

    /** A public instructor profile with bilingual headline/bio (U4). */
    public function instructor(): static
    {
        return $this->state(fn (): array => [
            'headline_i18n' => ['en' => 'Senior Instructor', 'ar' => 'مدرب أول'],
            'bio_i18n' => ['en' => 'Seasoned educator.', 'ar' => 'مربٍّ متمرس.'],
            'specialties' => ['Leadership', 'Strategy'],
            'social_links' => ['linkedin' => 'https://example.com/in/instructor'],
            'website' => 'https://example.com',
            'is_public' => true,
        ]);
    }
}
