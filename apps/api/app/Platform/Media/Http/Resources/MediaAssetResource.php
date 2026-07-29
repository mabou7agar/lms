<?php

namespace App\Platform\Media\Http\Resources;

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property MediaAsset $resource
 *
 * P2/W04 - Client-safe view of an asset. NEVER exposes provider_ref, storage_key, playback_id,
 * thumbnail_ref, the upload token, or the internal id — the public_id is the sole external
 * identifier. A signed playback URL is issued separately via PlaybackPort, never embedded here.
 */
class MediaAssetResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'type' => $this->resource->type->value,
            'status' => $this->resource->status->value,
            'purpose' => $this->resource->purpose->value,
            'provider' => $this->resource->provider->value,
            'original_filename' => $this->resource->original_filename,
            'mime_type' => $this->resource->mime_type,
            'size_bytes' => $this->resource->size_bytes,
            'duration_seconds' => $this->resource->duration_seconds,
            'width' => $this->resource->width,
            'height' => $this->resource->height,
            'processing_progress' => $this->resource->processing_progress,
            'is_ready' => $this->resource->status->isPlayable(),
            'failure_code' => $this->resource->failure_code,
            'failure_message' => $this->resource->failure_message,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
