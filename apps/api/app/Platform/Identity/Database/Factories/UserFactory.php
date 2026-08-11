<?php

namespace App\Platform\Identity\Database\Factories;

use App\Platform\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+9665'.fake()->numerify('########'),
            'password' => Hash::make('password'),
            // Factory users are password-capable by default (mirrors a normal registration).
            'password_set_at' => now(),
            'locale' => 'en',
            'is_active' => true,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A social-only account: a random, unknown password and NO password_set_at, so it has no usable
     * password. Used to test unlink orphan-safety (the last provider may not be removed).
     */
    public function socialOnly(): static
    {
        return $this->state(fn () => [
            'password' => Hash::make(Str::random(40)),
            'password_set_at' => null,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withMfa(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn () => [
            'mfa_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['code-one', 'code-two'],
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
