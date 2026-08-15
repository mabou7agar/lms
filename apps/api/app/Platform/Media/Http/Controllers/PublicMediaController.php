<?php

namespace App\Platform\Media\Http\Controllers;

use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Enums\MediaProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-hosted delivery for GLOBAL PUBLIC media (the counterpart to PublicMediaUrlBuilder, which mints
 * the stable /media/public/{publicId}?v=... URL). In production public delivery is fronted by a CDN
 * pointed at object storage; this route makes the SAME public URL resolvable without a CDN — required
 * for the local dev media store, and harmless anywhere else.
 *
 * It serves ONLY an asset that is explicitly Public, Ready, and GLOBAL (no owning org): exactly the
 * assets PublicMediaUrlBuilder is willing to address with an unauthenticated URL. Anything else 404s,
 * so this never becomes a way to read a private, pending, or tenant-owned object. The object is keyed
 * by public_id (never a storage key), and the response is immutably cacheable because the caller's URL
 * is fingerprinted.
 */
class PublicMediaController
{
    public function __invoke(string $publicId): Response|RedirectResponse
    {
        $asset = MediaAsset::query()->where('public_id', $publicId)->first();

        abort_if(
            $asset === null
                || $asset->visibility !== MediaVisibility::Public
                || ! $asset->status->isPlayable()
                || $asset->organization_id !== null
                || $asset->storage_key === null,
            404,
        );

        $mime = $asset->mime_type ?: 'application/octet-stream';
        $headers = [
            'Content-Type' => $mime,
            // The URL is content-fingerprinted (?v=), so a replaced asset gets a fresh URL — cache hard.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];

        return match ($asset->provider) {
            // Buffered (in-memory) response rather than a StreamedResponse: the served objects are small
            // (thumbnails/avatars/logos) and a streamed body is unreliable under Laravel Octane's captured
            // output, 503-ing under a page's concurrent image burst. Local delivery is dev-only (prod media
            // lives on S3 and takes the redirect branch below), so this never affects production streaming.
            MediaProvider::Local => response(
                (string) Storage::disk((string) config('media.local.disk', 'media_local'))->get($asset->storage_key),
                200,
                $headers,
            ),
            // Hosted object storage: hand off to a short-lived signed object URL (dev parity; prod uses a CDN).
            MediaProvider::S3 => redirect()->away(
                Storage::disk((string) config('media.s3.disk', 's3'))
                    ->temporaryUrl($asset->storage_key, now()->addMinutes(5)),
            ),
            // Fake/Mux/External hold no self-servable public bytes at this route.
            default => abort(404),
        };
    }
}
