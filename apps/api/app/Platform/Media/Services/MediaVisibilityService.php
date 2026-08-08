<?php

namespace App\Platform\Media\Services;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Support\Facades\Gate;

/**
 * P1 - The ONE authorized, audited path that sets a media asset's visibility. Visibility is a
 * server-side decision: it is never inferred from a storage path and a client payload can NEVER flip
 * PRIVATE -> PUBLIC through it, because every entry point authorizes first.
 *
 * Two entry points, both gated:
 *   - setVisibility(): admin action, policy-gated via MediaAssetPolicy@setVisibility (owner or course
 *     manager; super_admin bypasses). Raising exposure is the security-sensitive edge and is exactly
 *     what the policy check protects; a forged/unauthorized caller is rejected with a domain exception.
 *   - markPublicForOwner(): the picker/upload path's server-side request to make a freshly-uploaded
 *     IMAGE public for a CMS/branding/thumbnail surface. Only the asset's OWNER may trigger it and
 *     only for image assets (stable public delivery is for imagery); anything else is a no-op/deny.
 *
 * Every change is written through forceFill()+save() (nothing is mass-assignable) and audited.
 *
 * TENANCY NOTE (T1, later phase): both entry points must also assert the actor and the asset belong
 * to the same organization before a raise is allowed.
 */
class MediaVisibilityService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Admin action: set an asset's visibility. Policy-gated; throws when the actor may not manage the
     * asset (this is what stops an unauthorized PRIVATE -> PUBLIC raise from a forged request).
     *
     * @throws MediaAccessDeniedException when the actor is not authorized to change this asset.
     */
    public function setVisibility(MediaAsset $asset, MediaVisibility $visibility, Actor $actor): MediaAsset
    {
        if (! Gate::forUser($actor)->allows('setVisibility', $asset)) {
            throw new MediaAccessDeniedException;
        }

        return $this->apply($asset, $visibility, $actor->actorId(), 'admin');
    }

    /**
     * Server-side request from the picker/upload path to publish an uploaded IMAGE. Owner-only, image-
     * only; a non-owner is denied and a non-image is left unchanged (never widened implicitly).
     *
     * @throws MediaAccessDeniedException when $actorId does not own the asset.
     */
    public function markPublicForOwner(MediaAsset $asset, int $actorId): MediaAsset
    {
        if ($asset->created_by !== $actorId) {
            throw new MediaAccessDeniedException;
        }

        if ($asset->type !== MediaType::Image) {
            return $asset;
        }

        if ($asset->visibility === MediaVisibility::Public) {
            return $asset;
        }

        return $this->apply($asset, MediaVisibility::Public, $actorId, 'picker');
    }

    private function apply(MediaAsset $asset, MediaVisibility $to, int $actorId, string $via): MediaAsset
    {
        $from = $asset->visibility;

        $asset->forceFill(['visibility' => $to->value])->save();

        $this->audit->log('media.visibility.changed', $asset, [
            'from' => $from instanceof MediaVisibility ? $from->value : (string) $from,
            'to' => $to->value,
            'via' => $via,
        ], $actorId);

        return $asset;
    }
}
