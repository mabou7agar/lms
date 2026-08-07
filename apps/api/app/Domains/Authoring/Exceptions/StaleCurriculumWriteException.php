<?php

namespace App\Domains\Authoring\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * C3 - Optimistic-locking conflict on a curriculum node (section/lesson).
 *
 * Thrown when a caller's `expected_version` no longer matches the row's authoritative
 * `lock_version` — a lost-update / stale-write attempt. No changes are persisted: it is raised
 * from inside the same short transaction that lockForUpdate-held the row, so the first writer's
 * state is preserved intact.
 *
 * It deliberately renders a FLAT, dedicated body rather than the standard domain error envelope,
 * because the builder reconciles on `current_version` and this shape is a stable client contract:
 *
 *   { "error": "stale_write", "current_version": <int> }
 */
class StaleCurriculumWriteException extends AuthoringException
{
    protected string $errorCode = 'stale_write';

    protected int $status = 409;

    public function __construct(private readonly int $currentVersion)
    {
        parent::__construct('The curriculum node was modified by another writer.', [
            'current_version' => $currentVersion,
        ]);
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    /**
     * Override the base envelope with the exact 409 contract the front-end expects.
     * Laravel calls this to render the exception.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'stale_write',
            'current_version' => $this->currentVersion,
        ], $this->status);
    }
}
