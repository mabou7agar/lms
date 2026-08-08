<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Platform\Identity\Contracts\Data\InstructorProfileRef;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Public instructor page representation (U4). `profile_photo` / `cover_photo` / `avatar_path` are
 * stored as REFERENCES (a MediaAsset public_id) or legacy paths/URLs; P1 resolves them here through
 * PublicAssetUrlResolver — a PUBLIC asset becomes a stable URL, a legacy value passes through, and a
 * private/missing asset becomes null (never a raw reference or storage key). Field names unchanged.
 * Bilingual headline/bio are exposed both resolved and as full locale maps.
 *
 * @property InstructorProfileRef $resource
 */
class InstructorProfileResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        $media = app(PublicAssetUrlResolver::class);

        return [
            'id' => $this->resource->publicId,
            'name' => $this->resource->name,
            'headline' => $this->resource->headline,
            'headline_i18n' => $this->resource->headlineI18n,
            'bio' => $this->resource->bio,
            'bio_i18n' => $this->resource->bioI18n,
            'specialties' => $this->resource->specialties,
            'social_links' => $this->resource->socialLinks,
            'website' => $this->resource->website,
            // Media references resolved to public URLs (P1); legacy paths pass through unchanged.
            'profile_photo' => $media->resolve($this->resource->profilePhoto),
            'cover_photo' => $media->resolve($this->resource->coverPhoto),
            'avatar_path' => $media->resolve($this->resource->avatarPath),
        ];
    }
}
