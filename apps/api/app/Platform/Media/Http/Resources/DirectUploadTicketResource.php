<?php

namespace App\Platform\Media\Http\Resources;

use App\Platform\Media\Ingestion\Data\DirectUploadTicket;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property DirectUploadTicket $resource
 *
 * P2/W04 - The upload ticket returned to the browser: the asset, the opaque provider upload
 * instructions (signed URL + method/headers/fields the client PUTs/POSTs bytes to — the provider
 * ref is intentionally NOT exposed), and the single-use finalize token to return once the upload
 * completes.
 */
class DirectUploadTicketResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $t = $this->resource;

        return [
            'media' => (new MediaAssetResource($t->asset))->toArray($request),
            'upload' => [
                'url' => $t->instructions->uploadUrl,
                'method' => $t->instructions->method,
                'headers' => $t->instructions->headers,
                'fields' => $t->instructions->fields,
                'expires_at' => $t->instructions->expiresAt->toIso8601String(),
            ],
            'upload_token' => $t->uploadToken,
        ];
    }
}
