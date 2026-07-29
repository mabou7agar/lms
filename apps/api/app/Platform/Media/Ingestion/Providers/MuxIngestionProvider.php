<?php

namespace App\Platform\Media\Ingestion\Providers;

use App\Platform\Media\Exceptions\MediaWebhookSignatureException;
use App\Platform\Shared\Media\Contracts\IngestionProvider;
use App\Platform\Shared\Media\Data\DirectUploadInstructions;
use App\Platform\Shared\Media\Data\DirectUploadRequest;
use App\Platform\Shared\Media\Data\ProviderAssetStatus;
use App\Platform\Shared\Media\Data\ProviderWebhookEvent;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaStatus;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * P2/W04 - Mux ingestion for streamed video/audio. Creates a Mux Direct Upload (browser PUTs bytes
 * straight to Mux — never through the app), reads authoritative asset state on verify, and verifies
 * the Mux-Signature HMAC on webhooks. All Mux credentials are read only here (config/media.mux +
 * webhook secret). Playback SIGNING stays in MuxPlaybackSigner; this adapter only ingests.
 */
class MuxIngestionProvider implements IngestionProvider
{
    /** @param array<string, mixed> $config config('media.mux') */
    public function __construct(private readonly array $config) {}

    public function name(): MediaProvider
    {
        return MediaProvider::Mux;
    }

    public function createDirectUpload(DirectUploadRequest $request): DirectUploadInstructions
    {
        $response = $this->client()->post('/video/v1/uploads', [
            'cors_origin' => (string) ($this->config['cors_origin'] ?? '*'),
            'new_asset_settings' => [
                'playback_policy' => ['signed'],
                'passthrough' => $request->mediaPublicId,
            ],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Mux upload creation failed: '.$response->status());
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->json('data', []);

        return new DirectUploadInstructions(
            providerRef: (string) ($data['id'] ?? ''),
            uploadUrl: (string) ($data['url'] ?? ''),
            method: 'PUT',
            headers: [],
            fields: [],
            expiresAt: now()->addSeconds((int) config('media.upload.token_ttl_seconds', 3600)),
        );
    }

    public function verifyUpload(string $providerRef): ProviderAssetStatus
    {
        // The provider ref is the Mux upload id; resolve it to its asset, then read asset state.
        $upload = $this->client()->get("/video/v1/uploads/{$providerRef}");
        $assetId = (string) $upload->json('data.asset_id', '');

        if ($assetId === '') {
            return new ProviderAssetStatus(status: MediaStatus::Uploaded);
        }

        $asset = $this->client()->get("/video/v1/assets/{$assetId}");
        /** @var array<string, mixed> $data */
        $data = (array) $asset->json('data', []);

        return $this->mapAsset($data);
    }

    public function deleteRemote(string $providerRef): void
    {
        // providerRef may be an upload id; resolve to the asset then delete. 404 is a no-op.
        $upload = $this->client()->get("/video/v1/uploads/{$providerRef}");
        $assetId = (string) $upload->json('data.asset_id', '');

        if ($assetId !== '') {
            $this->client()->delete("/video/v1/assets/{$assetId}");
        }
    }

    public function parseWebhook(string $payload, array $headers): ProviderWebhookEvent
    {
        $this->verifySignature($payload, $headers);

        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($payload, true) ?: [];
        /** @var array<string, mixed> $object */
        $object = (array) ($envelope['data'] ?? []);
        $type = (string) ($envelope['type'] ?? '');

        // Mux ties the event to our asset via passthrough (the media public id) OR the upload id.
        $providerRef = (string) ($object['upload_id'] ?? $object['passthrough'] ?? $object['id'] ?? '');

        return new ProviderWebhookEvent(
            id: (string) ($envelope['id'] ?? ''),
            type: $type,
            providerRef: $providerRef,
            status: $this->mapEvent($type, $object),
        );
    }

    /** @param array<string, mixed> $data */
    private function mapAsset(array $data): ProviderAssetStatus
    {
        $muxStatus = (string) ($data['status'] ?? '');
        $status = match ($muxStatus) {
            'ready' => MediaStatus::Ready,
            'errored' => MediaStatus::Failed,
            default => MediaStatus::Processing,
        };

        $playback = null;
        if (isset($data['playback_ids'][0]['id'])) {
            $playback = (string) $data['playback_ids'][0]['id'];
        }

        return new ProviderAssetStatus(
            status: $status,
            providerAssetRef: isset($data['id']) ? (string) $data['id'] : null,
            playbackId: $playback,
            mimeType: null,
            sizeBytes: null,
            durationSeconds: isset($data['duration']) ? (int) round((float) $data['duration']) : null,
            width: null,
            height: null,
            failureCode: isset($data['errors']['type']) ? (string) $data['errors']['type'] : null,
            failureMessage: isset($data['errors']['messages'][0]) ? (string) $data['errors']['messages'][0] : null,
        );
    }

    /** @param array<string, mixed> $object */
    private function mapEvent(string $type, array $object): ProviderAssetStatus
    {
        return match ($type) {
            'video.asset.ready' => $this->mapAsset($object),
            'video.upload.asset_created', 'video.asset.created' => new ProviderAssetStatus(status: MediaStatus::Processing),
            'video.asset.errored', 'video.upload.errored' => new ProviderAssetStatus(
                status: MediaStatus::Failed,
                failureCode: 'mux_error',
                failureMessage: isset($object['errors']['messages'][0]) ? (string) $object['errors']['messages'][0] : null,
            ),
            default => new ProviderAssetStatus(status: MediaStatus::Uploaded),
        };
    }

    /** @param array<string, string> $headers */
    private function verifySignature(string $payload, array $headers): void
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $header = $headers['Mux-Signature'] ?? $headers['mux-signature'] ?? '';

        if ($secret === '' || $header === '') {
            throw new MediaWebhookSignatureException('Missing Mux signature or secret.');
        }

        // Header form: "t=<timestamp>,v1=<hex hmac of "t.payload">".
        $parts = [];
        foreach (explode(',', $header) as $segment) {
            [$k, $v] = array_pad(explode('=', $segment, 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = (string) ($parts['v1'] ?? '');
        $tolerance = (int) ($this->config['webhook_tolerance'] ?? 300);

        if ($timestamp <= 0 || abs(now()->getTimestamp() - $timestamp) > $tolerance) {
            throw new MediaWebhookSignatureException('Mux webhook timestamp outside tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new MediaWebhookSignatureException('Invalid Mux webhook signature.');
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) ($this->config['base_url'] ?? 'https://api.mux.com'))
            ->withBasicAuth((string) ($this->config['token_id'] ?? ''), (string) ($this->config['token_secret'] ?? ''))
            ->acceptJson()
            ->asJson();
    }
}
