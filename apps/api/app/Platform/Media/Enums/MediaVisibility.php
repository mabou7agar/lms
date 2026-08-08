<?php

namespace App\Platform\Media\Enums;

/**
 * P1 - How broadly a media asset may be served. This is the ONLY input to public-URL resolution;
 * it is set exclusively through the authorized, audited MediaVisibilityService, NEVER inferred from
 * a storage path and NEVER flipped by a client payload.
 *
 * Secure by default: every asset (existing and new) is PRIVATE until an authorized actor raises it.
 *
 *   - Private       -> not exposed in a public context; playable only through a per-request signed URL
 *                      after a policy/enrollment check (the existing PlaybackPort + MediaAssetPolicy).
 *   - Authenticated -> resolvable to a short-lived SIGNED URL for a public renderer (still tokenized).
 *   - Public        -> resolvable to a STABLE, fingerprinted public URL safe for long CDN caching
 *                      (CMS / branding / thumbnail imagery an admin has deliberately made public).
 */
enum MediaVisibility: string
{
    case Private = 'private';
    case Authenticated = 'authenticated';
    case Public = 'public';

    /** True when raising FROM $this TO $to widens exposure (needs an explicit authorized action). */
    public function isRaiseTo(self $to): bool
    {
        return $this->rank() < $to->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Private => 0,
            self::Authenticated => 1,
            self::Public => 2,
        };
    }
}
