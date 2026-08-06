<?php

namespace App\Platform\Identity\Models;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Identity\Database\Factories\UserFactory;
use App\Platform\Identity\Notifications\ResetPasswordNotification;
use App\Platform\Shared\Traits\HasPublicId;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Identity aggregate root. Owns account state, verification flags, lockout, and MFA storage.
 * External references use `public_id`; the bigint id is internal only.
 */
class User extends Authenticatable implements Actor, FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasPublicId;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'locale', 'timezone', 'is_active',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_enabled' => 'boolean',
            'failed_login_count' => 'integer',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    // ----- Relations -----

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function otps(): HasMany
    {
        return $this->hasMany(UserOtp::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    // ----- Lifecycle state -----

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function markEmailVerified(): void
    {
        $this->forceFill(['email_verified_at' => now()])->save();
    }

    public function markPhoneVerified(): void
    {
        $this->forceFill(['phone_verified_at' => now()])->save();
    }

    // ----- Contracts -----

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Actor: stable internal id. Mirrors `$user->id` used across ownership checks (the bigint key).
     * `hasRole()` (also part of Actor) is already provided by the Spatie HasRoles trait.
     */
    public function actorId(): int
    {
        return (int) $this->getKey();
    }

    /**
     * Actor: guard-independent permission check.
     *
     * The `web` guard is pinned explicitly because permissions are seeded against it. Without that,
     * a request authenticated by `auth:sanctum` resolves the sanctum guard, finds no matching
     * permission row, and reports false for a user who genuinely holds it — which is why
     * `$user->can()` has never been reliable on the API and why authorization here reads roles in
     * places it should read permissions.
     *
     * `checkPermissionTo()` rather than `hasPermissionTo()`: the former returns false for an
     * unregistered permission, the latter throws, and an authorization check should deny rather
     * than 500 when asked about a permission that does not exist.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->checkPermissionTo($permission, 'web');
    }

    /**
     * Actor: boundary-safe projection. Reads only public display fields; never exposes the
     * $hidden secrets or account/PII internals. Uses the loaded profile relation when available.
     */
    public function toUserRef(): UserRef
    {
        return new UserRef(
            id: (int) $this->getKey(),
            publicId: (string) $this->public_id,
            name: (string) $this->name,
            avatarPath: $this->profile?->avatar_path,
            headline: $this->profile?->bio,
        );
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasAnyRole(['super_admin', 'admin']);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
