<?php

namespace App\Platform\Shared\Media\Contracts;

/**
 * The safe seam the reusable Filament MediaPicker (a Shared form component) uses to reach the Media
 * platform WITHOUT importing any Media class. The Shared component depends only on this interface;
 * the Media platform binds a concrete implementation (see MediaPickerAdapter) that owns every
 * Media-side concern — searching the actor's selectable assets, re-authorizing a picked id, signing
 * a short-lived preview URL, and performing a server-side upload.
 *
 * All media "types" and "purposes" cross this boundary as their STRING values (the backed-enum
 * ->value), so neither the Shared component nor any consuming domain ever imports a Media/Shared
 * media enum. The implementation maps those strings to the concrete enums internally.
 *
 * The stored value contract is unchanged: a picked asset is identified by its MediaAsset `public_id`
 * (never a raw storage/provider key). "Missing" and "not owned" are deliberately indistinguishable
 * so a picker can never be used to probe which asset ids exist.
 */
interface MediaPickerPort
{
    /**
     * Owner + type scoped, ready-only asset options for the "Select existing" modal, matching the
     * search term against filename or public_id.
     *
     * @param  list<string>  $acceptedTypes  MediaType ->value strings; empty = any type.
     * @return array<string, string>  public_id => human label (filename, falling back to the id).
     */
    public function searchAssets(int $actorId, array $acceptedTypes, ?string $search): array;

    /**
     * A short-lived, signed preview URL for a picked reference, or null when the asset is missing /
     * not yet playable / cannot be signed. Never returns a raw storage key or provider ref.
     */
    public function previewUrl(string $publicId): ?string;

    /**
     * Re-authorize a picked asset public id for the given actor, or throw. The actor must own the
     * asset and its type must be accepted for the field/purpose.
     *
     * @param  list<string>  $acceptedTypes  MediaType ->value strings; empty = any type.
     * @param  string|null  $purpose  MediaPurpose ->value string, or null.
     * @param  int|null  $ownerScope  Optional tenant/owner scope; null = the acting user only.
     */
    public function assertSelectable(
        string $publicId,
        int $actorId,
        array $acceptedTypes,
        ?string $purpose,
        ?int $ownerScope,
    ): void;

    /**
     * Non-throwing form of assertSelectable(): true when the id is a valid, owned, accepted reference.
     *
     * @param  list<string>  $acceptedTypes  MediaType ->value strings; empty = any type.
     */
    public function isSelectable(
        string $publicId,
        int $actorId,
        array $acceptedTypes,
        ?string $purpose,
        ?int $ownerScope,
    ): bool;

    /**
     * Upload a new asset through the Media engine and return its stored `public_id` reference.
     *
     * @param  string  $purpose  MediaPurpose ->value string; bounds the accepted type + size.
     */
    public function upload(
        int $actorId,
        string $purpose,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $contents,
    ): string;
}
