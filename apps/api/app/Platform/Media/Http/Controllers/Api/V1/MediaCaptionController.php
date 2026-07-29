<?php

namespace App\Platform\Media\Http\Controllers\Api\V1;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Media\Http\Requests\StoreCaptionRequest;
use App\Platform\Media\Http\Resources\MediaCaptionResource;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaCaption;
use App\Platform\Media\Services\MediaCaptionService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * P2/W04 - Caption CRUD for an asset (metadata only; no transcription). Manager-only via
 * MediaAssetPolicy 'caption'.
 */
class MediaCaptionController
{
    public function __construct(private readonly MediaCaptionService $captions) {}

    public function index(Request $request, MediaAsset $media): JsonResponse
    {
        $this->authorize($request, $media);

        $captions = $this->captions->listCaptions($media)
            ->map(fn (MediaCaption $c) => (new MediaCaptionResource($c))->resolve());

        return ApiResponse::success($captions->values());
    }

    public function store(StoreCaptionRequest $request, MediaAsset $media): JsonResponse
    {
        $actor = $this->authorize($request, $media);

        $caption = $this->captions->addCaption(
            asset: $media,
            actorId: $actor->actorId(),
            language: (string) $request->validated('language'),
            label: (string) $request->validated('label'),
            format: (string) ($request->validated('format') ?? 'vtt'),
            storageKey: $request->validated('storage_key'),
            providerRef: $request->validated('provider_ref'),
        );

        return ApiResponse::created(new MediaCaptionResource($caption), 'Caption added.');
    }

    public function destroy(Request $request, MediaAsset $media, MediaCaption $caption): JsonResponse
    {
        $actor = $this->authorize($request, $media);

        $this->captions->removeCaption($media, $caption, $actor->actorId());

        return ApiResponse::deleted('Caption removed.');
    }

    private function authorize(Request $request, MediaAsset $media): Actor
    {
        $actor = $request->user();

        if (! $actor instanceof Actor || ! Gate::forUser($actor)->allows('caption', $media)) {
            throw new NotFoundHttpException('Media not found.');
        }

        return $actor;
    }
}
