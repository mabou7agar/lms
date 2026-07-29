<?php

namespace App\Platform\Media\Http\Resources;

use App\Platform\Media\Models\MediaCaption;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property MediaCaption $resource
 *
 * P2/W04 - Client-safe caption view. Exposes the public id + language/label/status only, never the
 * raw storage key or provider ref.
 */
class MediaCaptionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'language' => $this->resource->language,
            'label' => $this->resource->label,
            'format' => $this->resource->format,
            'status' => $this->resource->status->value,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
