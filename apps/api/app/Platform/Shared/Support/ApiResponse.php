<?php

namespace App\Platform\Shared\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Central helper for building the ONE standard API envelope used across every domain.
 *
 * Success envelope:  { "data": ..., "message": ?string, "meta": ?object }
 * Error envelope:    { "error": { code, message, details, correlation_id, timestamp } }
 * Paginated:         { "data": [...], "meta": {...}, "links": {...} }
 *
 * This class contains NO business logic — it is pure response shaping.
 */
final class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = [], int $encodingOptions = 0): JsonResponse
    {
        $payload = ['data' => $data];

        if ($message !== null) {
            $payload['message'] = $message;
        }
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status, [], $encodingOptions);
    }

    public static function created(mixed $data = null, ?string $message = 'Created.'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function updated(mixed $data = null, ?string $message = 'Updated.'): JsonResponse
    {
        return self::success($data, $message, 200);
    }

    public static function deleted(?string $message = 'Deleted.'): JsonResponse
    {
        return self::success(null, $message, 200);
    }

    /**
     * Build the standard error envelope. `code` is a stable, machine-readable string
     * (e.g. VALIDATION_ERROR); `details` is an arbitrary array of supporting info.
     */
    public static function error(string $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'correlation_id' => self::correlationId(),
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }

    /**
     * Shape a Laravel paginator into the standard paginated envelope. Optionally map each
     * item through an API Resource class.
     *
     * @param  class-string<JsonResource>|null  $resourceClass
     */
    public static function paginated(LengthAwarePaginator $paginator, ?string $resourceClass = null): JsonResponse
    {
        $items = $paginator->getCollection();

        if ($resourceClass !== null) {
            $items = $items->map(fn ($item) => (new $resourceClass($item))->resolve());
        }

        return response()->json([
            'data' => $items->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Correlation id for tracing: reuse an incoming X-Correlation-ID header if present,
     * otherwise mint a fresh one.
     */
    public static function correlationId(): string
    {
        $incoming = request()?->header('X-Correlation-ID');

        return is_string($incoming) && $incoming !== '' ? $incoming : (string) Str::uuid();
    }
}
