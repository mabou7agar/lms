<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Events\MediaReplaced;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaAttachment;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 / D2 - Safe replace.
 *
 * SEMANTICS (chosen deliberately): "create a new asset version and repoint references", NOT "replace
 * the binary of the same asset". The existing engine is forward-only (MediaStatus never moves
 * backwards) and pins a UNIQUE provider_ref per row, so re-ingesting a new binary into the same row
 * would fight both invariants and destroy the audit trail of the original. Versioning instead keeps
 * the original row intact (then retires it) and moves every usage reference onto the freshly-ingested
 * asset, which preserves history and guarantees no reference is ever left pointing at nothing.
 *
 * The repoint reuses the engine's own MediaAttachmentService (attach the new asset to each place the
 * old one was used, then detach the old), so all of its guarantees ride along: ownership, readiness,
 * cross-course scoping, idempotency and audit. The whole repoint runs in ONE transaction: if any
 * single attach/detach is rejected (e.g. the new asset is not Ready) the transaction rolls back and
 * the original asset and all of its references are left exactly as they were. Only AFTER the repoint
 * commits is the retired original handed to MediaDeletionService, which drives its status to Deleted
 * and purges the old provider object (best-effort, idempotent) — by then it has zero references, so
 * the normal (non-forced) delete path applies and no orphan can remain. Signed URLs are always
 * derived on demand from the live asset via PlaybackPort, so "regenerating" them is automatic: the
 * repointed reference now resolves to the new asset.
 */
class MediaReplacementService
{
    public function __construct(
        private readonly MediaAttachmentService $attachments,
        private readonly MediaDeletionService $deletion,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Repoint every usage of $old onto $new, then retire $old. Both assets must be owned by $actorId
     * (the engine's owner-only mutation rule); $new must be Ready. Returns the number of references
     * that were repointed.
     *
     * @throws \App\Platform\Media\Exceptions\MediaAccessDeniedException when the actor owns neither asset.
     * @throws \App\Platform\Media\Exceptions\MediaNotReadyException when $new is not Ready.
     * @throws \App\Platform\Media\Exceptions\MediaValidationException on a cross-course mismatch.
     */
    public function replace(MediaAsset $old, MediaAsset $new, int $actorId): int
    {
        // Repoint atomically. attach()/detach() each take a row lock on the asset they touch and open
        // their own savepoint, so a rejection anywhere unwinds the whole repoint — the original keeps
        // all of its references.
        $repointed = DB::transaction(function () use ($old, $new, $actorId): int {
            $usages = MediaAttachment::query()
                ->where('media_asset_id', $old->getKey())
                ->orderBy('id')
                ->get();

            foreach ($usages as $usage) {
                // Attach the new asset to the same target first (validated by the engine), THEN drop
                // the old edge. For a moment both exist; the net result is a clean repoint with no
                // window in which the reference points at nothing.
                $this->attachments->attach(
                    $new,
                    $actorId,
                    $usage->attachable_type,
                    $usage->attachable_id,
                    $usage->role,
                    $usage->course_id,
                );

                $this->attachments->detach($old, $actorId, $usage->attachable_type, $usage->attachable_id);
            }

            return $usages->count();
        });

        // The original now has zero references: the ordinary (non-forced) delete applies. This drives
        // it to Deleted and purges the old provider object after its own transaction commits.
        $this->deletion->deleteAsset($old, $actorId, force: false);

        MediaReplaced::dispatch((string) $old->public_id, (string) $new->public_id, $actorId);
        $this->audit->log('media.replaced', $new, [
            'replaced_public_id' => (string) $old->public_id,
            'references_repointed' => $repointed,
        ], $actorId);

        return $repointed;
    }
}
