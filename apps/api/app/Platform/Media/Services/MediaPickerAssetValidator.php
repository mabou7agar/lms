<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Shared\Media\Contracts\MediaReferencePort;
use App\Platform\Shared\Media\Data\MediaReference;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;

/**
 * Phase 6 / U1 - The trust boundary of the reusable Filament MediaPicker. A picked asset id ALWAYS
 * arrives from the browser, so it is never taken at face value: this validator re-checks ownership
 * and the accepted type/purpose against the existing safe seam (MediaReferencePort) before the id is
 * allowed to become the stored reference. It never imports the MediaAsset model, never returns a
 * storage key / provider ref, and treats "missing" and "not owned" as indistinguishable so a picker
 * cannot be used to probe which asset ids exist.
 *
 * Ownership rule mirrors the engine's own "usable by" rule (MediaReferenceAdapter::assertUsableBy /
 * MediaAttachmentService::attach): the actor must be the asset's creator. Readiness is deliberately
 * NOT required here — a picker may hold a reference to an asset that is still processing and simply
 * shows the preview once it becomes Ready. The optional $ownerScope narrows the acceptable owner when
 * supplied. T1 tenant isolation is enforced UPSTREAM: reference() rides MediaAsset's
 * SharedOrOwnedTenantScope, so a public_id owned by another organization already resolves to null here
 * (indistinguishable from missing) — this class adds no scope logic of its own.
 */
class MediaPickerAssetValidator
{
    public function __construct(private readonly MediaReferencePort $references) {}

    /**
     * Resolve and authorize a picked asset public id for the given actor, or throw.
     *
     * @param  list<MediaType>  $acceptedTypes  Empty = any type is accepted.
     * @param  int|null  $ownerScope  Optional tenant/owner scope; null = the acting user only.
     *
     * @throws MediaAccessDeniedException when the asset does not exist or is not owned by the actor.
     * @throws MediaValidationException when the asset's type is not accepted for this field/purpose.
     */
    public function validate(
        string $publicId,
        int $actorId,
        array $acceptedTypes = [],
        ?MediaPurpose $purpose = null,
        ?int $ownerScope = null,
    ): MediaReference {
        $reference = $this->references->reference($publicId);

        // Missing OR not-owned are deliberately indistinguishable (no existence leak), exactly as the
        // engine's MediaReferenceAdapter::assertUsableBy behaves.
        if ($reference === null || $reference->ownerActorId !== $actorId) {
            throw new MediaAccessDeniedException;
        }

        // Tenant-ready narrowing only — no tenancy logic is implemented here (T1 out of scope).
        if ($ownerScope !== null && $reference->ownerActorId !== $ownerScope) {
            throw new MediaAccessDeniedException;
        }

        if ($acceptedTypes !== [] && ! in_array($reference->type, $acceptedTypes, true)) {
            throw new MediaValidationException(
                'The selected media type is not accepted here.',
                ['field' => 'type', 'type' => $reference->type->value],
            );
        }

        if ($purpose !== null && ! $purpose->accepts($reference->type)) {
            throw new MediaValidationException(
                "The selected media is not valid for the {$purpose->value} purpose.",
                ['field' => 'purpose'],
            );
        }

        return $reference;
    }

    /**
     * Non-throwing convenience: true when the picked id is a valid, owned, accepted reference.
     *
     * @param  list<MediaType>  $acceptedTypes
     */
    public function passes(
        string $publicId,
        int $actorId,
        array $acceptedTypes = [],
        ?MediaPurpose $purpose = null,
        ?int $ownerScope = null,
    ): bool {
        try {
            $this->validate($publicId, $actorId, $acceptedTypes, $purpose, $ownerScope);

            return true;
        } catch (MediaAccessDeniedException|MediaValidationException) {
            return false;
        }
    }
}
