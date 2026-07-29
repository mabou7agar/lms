<?php

namespace App\Platform\Media\Http\Controllers\Api\V1;

use App\Platform\Media\Services\MediaIngestionService;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * P2/W04 - Provider webhook endpoints. Unauthenticated (no Sanctum, no CSRF, no tenant) — the
 * provider signature verified inside the adapter IS the control. Processing is idempotent + ordered
 * in MediaIngestionService, so a duplicate delivery returns a safe 200 and an out-of-order event is
 * dropped. An invalid signature throws MediaWebhookSignatureException (400) before any side effect.
 */
class MediaWebhookController extends Controller
{
    public function __construct(private readonly MediaIngestionService $ingestion) {}

    public function mux(Request $request): JsonResponse
    {
        return $this->handle($request, MediaProvider::Mux);
    }

    public function s3(Request $request): JsonResponse
    {
        return $this->handle($request, MediaProvider::S3);
    }

    /** Test/local endpoint for the credential-free fake adapter. */
    public function fake(Request $request): JsonResponse
    {
        return $this->handle($request, MediaProvider::Fake);
    }

    private function handle(Request $request, MediaProvider $provider): JsonResponse
    {
        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$this->headerName($key)] = (string) ($values[0] ?? '');
        }

        $this->ingestion->processWebhook($provider, $request->getContent(), $headers);

        return ApiResponse::success(null, 'ok');
    }

    /** Normalise "mux-signature" -> "Mux-Signature" so adapters can match either case. */
    private function headerName(string $key): string
    {
        return str_replace(' ', '-', ucwords(str_replace('-', ' ', $key)));
    }
}
