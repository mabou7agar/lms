<?php

namespace App\Domains\Authoring\Actions\Concerns;

use App\Domains\Authoring\Exceptions\StaleCurriculumWriteException;
use Illuminate\Database\Eloquent\Model;

/**
 * C3 - Shared optimistic-locking helpers for curriculum-node write actions.
 *
 * The compare-and-write happens INSIDE the caller's transaction against a row that has already
 * been lockForUpdate-selected, so the window between read and write is held by the row lock and
 * no two writers can both pass the guard for the same version. The lock is scoped to the
 * compare-and-write only — never across user think-time — keeping the transaction short.
 */
trait GuardsLockVersion
{
    /**
     * Reject a stale write. $expected is the version the caller believed it was editing; when it
     * is provided and no longer matches the row's authoritative `lock_version`, the write is a
     * lost update and is rejected with the current version so the client can reconcile.
     *
     * A null $expected keeps existing callers/tests backward compatible: no version was supplied,
     * so no optimistic check is performed and the write proceeds.
     */
    protected function assertLockVersion(Model $locked, ?int $expected): void
    {
        $current = (int) $locked->getAttribute('lock_version');

        if ($expected !== null && $expected !== $current) {
            throw new StaleCurriculumWriteException($current);
        }
    }

    /**
     * Advance the optimistic-lock counter on an already-locked row. The caller persists the row
     * (with its other mutations) in a single save, so this only stages the new value and returns
     * it for inclusion in the success payload.
     */
    protected function advanceLockVersion(Model $locked): int
    {
        $next = (int) $locked->getAttribute('lock_version') + 1;
        $locked->setAttribute('lock_version', $next);

        return $next;
    }
}
