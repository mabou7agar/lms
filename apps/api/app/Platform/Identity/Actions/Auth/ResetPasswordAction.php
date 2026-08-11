<?php

namespace App\Platform\Identity\Actions\Auth;

use App\Platform\Identity\Events\PasswordReset as PasswordResetEvent;
use App\Platform\Identity\Exceptions\PasswordResetFailedException;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordAction extends BaseAction
{
    /**
     * @param  array{email: string, password: string, password_confirmation: string, token: string}  $data
     */
    public function execute(array $data): void
    {
        $reset = null;

        $status = Password::broker()->reset($data, function (User $user, string $password) use (&$reset): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                // Setting a password makes a previously social-only account password-capable.
                'password_set_at' => now(),
            ])->save();

            // Invalidate all existing sessions on password reset.
            $user->tokens()->delete();
            $user->devices()->delete();
            $reset = $user;
        });

        if ($status !== Password::PASSWORD_RESET || $reset === null) {
            throw new PasswordResetFailedException('Invalid or expired reset token.');
        }

        PasswordResetEvent::dispatch($reset);
    }
}
