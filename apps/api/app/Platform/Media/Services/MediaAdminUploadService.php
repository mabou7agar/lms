<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Ingestion\Data\AdminUploadOutcome;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Data\DirectUploadInstructions;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use RuntimeException;
use Throwable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 1 / D5 + Phase 8 / D4 - Server-side upload orchestration for the Filament DAM. The DAM stays
 * upload-read-only in the sense that it never authors an asset row itself: every step here is
 * delegated to the EXISTING engine services, in the engine's own order —
 *
 *   1. MediaUploadService::createDirectUpload  (validates type/size vs purpose, mints the asset + a
 *      single-use finalize token — idempotent by (actor, idempotency_key))
 *   2. push the file bytes to the provider's signed upload URL (the one step the browser normally
 *      does; here the admin's file already lives on the server, so we forward it)
 *   3. MediaIngestionService::finalizeUpload    (spends the token, reads AUTHORITATIVE provider state)
 *
 * Nothing about the token/finalize/webhook/lifecycle is reimplemented. Size and type limits are the
 * engine's (enforced in step 1 against MediaPurpose), so this class adds no competing rules. Bulk
 * upload is just this run per file with an isolated try/catch, so one rejected file never aborts the
 * batch.
 */
class MediaAdminUploadService
{
    public function __construct(
        private readonly MediaUploadService $uploads,
        private readonly MediaIngestionService $ingestion,
    ) {}

    /**
     * Upload a single file through the engine and return the resulting (typically Ready) asset.
     *
     * @throws \App\Platform\Media\Exceptions\MediaValidationException on a type/size rejection.
     */
    public function upload(
        int $actorId,
        MediaPurpose $purpose,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $contents,
        ?MediaType $type = null,
        ?int $courseId = null,
    ): MediaAsset {
        $type ??= self::inferType($mimeType, $purpose);

        $ticket = $this->uploads->createDirectUpload(
            actorId: $actorId,
            type: $type,
            purpose: $purpose,
            filename: $filename,
            mimeType: $mimeType,
            sizeBytes: $sizeBytes,
            courseId: $courseId,
            idempotencyKey: (string) Str::uuid(),
        );

        $this->pushBytes($ticket->instructions, $contents, $mimeType);

        return $this->ingestion->finalizeUpload($ticket->asset, $ticket->uploadToken);
    }

    /**
     * Upload many files, isolating each so a partial failure is reported rather than aborting the
     * batch. Each entry must provide: filename, mime_type, size_bytes and contents.
     *
     * @param  iterable<int, array{filename: string, mime_type: string, size_bytes: int, contents: string, type?: MediaType|null}>  $files
     * @return list<AdminUploadOutcome>
     */
    public function uploadMany(int $actorId, MediaPurpose $purpose, iterable $files, ?int $courseId = null): array
    {
        $outcomes = [];

        foreach ($files as $file) {
            $filename = (string) ($file['filename'] ?? 'file');

            try {
                $asset = $this->upload(
                    actorId: $actorId,
                    purpose: $purpose,
                    filename: $filename,
                    mimeType: (string) ($file['mime_type'] ?? 'application/octet-stream'),
                    sizeBytes: (int) ($file['size_bytes'] ?? 0),
                    contents: (string) ($file['contents'] ?? ''),
                    type: $file['type'] ?? null,
                    courseId: $courseId,
                );

                $outcomes[] = AdminUploadOutcome::success($filename, $asset);
            } catch (Throwable $e) {
                // Isolation is the whole point of D4: record this file's failure and keep going.
                $outcomes[] = AdminUploadOutcome::failure($filename, $e->getMessage());
            }
        }

        return $outcomes;
    }

    /**
     * Best-fit media type for a purpose + mime. When a purpose accepts exactly one type that type
     * wins; otherwise the mime family decides. Kept deliberately conservative — the engine still has
     * the final say on acceptability in createDirectUpload.
     */
    public static function inferType(string $mimeType, MediaPurpose $purpose): MediaType
    {
        $allowed = $purpose->allowedTypes();

        if (count($allowed) === 1) {
            return $allowed[0];
        }

        $family = strtolower(strtok($mimeType, '/') ?: '');

        $candidate = match ($family) {
            'video' => MediaType::Video,
            'audio' => MediaType::Audio,
            'image' => MediaType::Image,
            'application', 'text' => MediaType::Document,
            default => MediaType::File,
        };

        // Never return a type the purpose does not accept; fall back to the first allowed type.
        return in_array($candidate, $allowed, true) ? $candidate : ($allowed[0] ?? MediaType::File);
    }

    /**
     * Forward the file bytes to the provider's signed upload URL. This is the only place the app
     * touches the object bytes and it exists purely because an admin upload originates on the server
     * rather than in the learner's browser. Idempotency/lifecycle remain the engine's.
     */
    private function pushBytes(DirectUploadInstructions $instructions, string $contents, string $mimeType): void
    {
        $headers = $instructions->headers;

        $request = Http::withHeaders($headers);

        if (strtoupper($instructions->method) === 'POST' && $instructions->fields !== []) {
            // S3-style browser POST: signed form fields + the file part.
            $multipart = $request->asMultipart();

            foreach ($instructions->fields as $name => $value) {
                $multipart = $multipart->attach($name, $value);
            }

            $response = $multipart
                ->attach('file', $contents, 'upload')
                ->post($instructions->uploadUrl);
        } else {
            // Presigned PUT (S3 object / Mux upload URL).
            $response = $request
                ->withBody($contents, $headers['Content-Type'] ?? $mimeType)
                ->put($instructions->uploadUrl);
        }

        if ($response->failed()) {
            throw new RuntimeException("The provider rejected the upload (HTTP {$response->status()}).");
        }
    }
}
