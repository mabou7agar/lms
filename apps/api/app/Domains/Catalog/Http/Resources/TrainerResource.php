<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Public trainer representation (a UserRef surfaced by the catalog). Exposes only public fields.
 *
 * `avatar_path` may hold a MediaAsset public_id reference (chosen via the MediaPicker) or a legacy
 * URL/path. It is resolved to a public URL through PublicAssetUrlResolver — a PUBLIC asset becomes a
 * stable URL, a legacy value passes through, a private/missing asset becomes null. Field name unchanged.
 *
 * @property UserRef $resource
 */
class TrainerResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->publicId,
            'name' => $this->resource->name,
            'headline' => $this->resource->headline,
            'avatar_path' => app(PublicAssetUrlResolver::class)->resolve($this->resource->avatarPath),
        ];
    }
}
