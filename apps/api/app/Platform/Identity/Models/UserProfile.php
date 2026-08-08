<?php

namespace App\Platform\Identity\Models;

use App\Platform\Identity\Database\Factories\UserProfileFactory;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's profile (1:1 with User). Besides the base account/display fields it also carries the
 * public INSTRUCTOR presentation (U4): bilingual headline/bio, MediaAsset-referenced profile & cover
 * photos, specialties, social links, website, directory ordering and public visibility.
 *
 * Media fields store a MediaAsset public_id reference (the value the shared MediaPicker stores) OR a
 * legacy path/URL — never a resolved/signed URL. i18n uses the shared HasTranslations pattern, so the
 * legacy scalar `bio` column stays synced from `bio_i18n` on write.
 */
class UserProfile extends Model
{
    /** @use HasFactory<UserProfileFactory> */
    use HasFactory;

    use HasPublicId;
    use HasTranslations;

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'avatar_path', 'bio', 'gender', 'date_of_birth', 'country', 'city',
        // Instructor profile (U4)
        'profile_photo', 'cover_photo', 'headline_i18n', 'bio_i18n', 'specialties', 'social_links',
        'website', 'display_order', 'is_public',
    ];

    /** @var array<int, string> Locale-aware JSON maps resolved through the central TranslationResolver. */
    protected array $translatable = ['headline_i18n', 'bio_i18n'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'headline_i18n' => 'array',
            'bio_i18n' => 'array',
            'specialties' => 'array',
            'social_links' => 'array',
            'display_order' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Domain-namespaced factory (Laravel's default resolver only finds Database\Factories\*). */
    protected static function newFactory(): UserProfileFactory
    {
        return UserProfileFactory::new();
    }
}
