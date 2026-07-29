<?php

namespace App\Platform\Shared\Media\Contracts;

use App\Platform\Shared\Media\Data\MediaReference;

/**
 * P2/W04 - The single safe seam other contexts use to reference a media asset by its PUBLIC id:
 * Authoring (attaching media to a block/lesson) and Assessment (a learner's submission file).
 * Callers never import the Media Eloquent model. Ownership/tenant checks live behind this port.
 */
interface MediaReferencePort
{
    /** Resolve a safe descriptor, or null if the asset does not exist / is not visible. */
    public function reference(string $mediaPublicId): ?MediaReference;

    /**
     * Assert the actor may USE this asset (owns it, and it is ready). Throws a domain exception
     * otherwise. Used before attaching media or accepting it as a submission file.
     */
    public function assertUsableBy(string $mediaPublicId, int $actorId): void;
}
