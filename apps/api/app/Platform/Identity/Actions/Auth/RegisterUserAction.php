<?php

namespace App\Platform\Identity\Actions\Auth;

use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Events\UserRegistered;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Actions\BaseAction;

class RegisterUserAction extends BaseAction
{
    /**
     * @param  array{name: string, email: string, password: string, phone?: ?string, locale?: ?string}  $data
     */
    public function execute(array $data): User
    {
        $user = $this->transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'en',
                'is_active' => true,
            ]);

            // Mark the account as password-capable (distinguishes it from a social-only account, which
            // leaves this null). Read by unlink orphan-safety.
            $user->forceFill(['password_set_at' => now()])->save();

            $user->profile()->create([]);
            $user->assignRole(Role::Student->value);

            return $user;
        });

        UserRegistered::dispatch($user);

        return $user;
    }
}
