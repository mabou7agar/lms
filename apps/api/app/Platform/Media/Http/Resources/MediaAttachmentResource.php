<?php

namespace App\Platform\Media\Http\Resources;

use App\Platform\Media\Models\MediaAttachment;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property MediaAttachment $resource
 *
 * P2/W04 - Client-safe usage record: which entity an asset is attached to, by scalar reference.
 */
class MediaAttachmentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'attachable_type' => $this->resource->attachable_type,
            'attachable_id' => $this->resource->attachable_id,
            'role' => $this->resource->role,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
