<?php

namespace App\Platform\Identity\Contracts\Data;

/**
 * Immutable, boundary-safe projection of a PUBLIC instructor profile (U4). Like UserRef this is the
 * only instructor-profile shape allowed to cross a context boundary, and it carries ONLY public
 * display fields — never account/PII internals.
 *
 * Media fields (profilePhoto / coverPhoto / avatarPath) are emitted as REFERENCES exactly as stored
 * (a MediaAsset public_id, or a legacy path/URL) — never a resolved/signed URL. Resolving a reference
 * to a public URL is a separate concern (the Media platform / P1). i18n fields are emitted both as
 * their full locale maps and as a resolver-picked scalar for the active locale.
 *
 * @property array<string, string> $headlineI18n
 * @property array<string, string> $bioI18n
 * @property array<int, string> $specialties
 * @property array<string, string> $socialLinks
 */
final readonly class InstructorProfileRef
{
    /**
     * @param  array<string, string>  $headlineI18n
     * @param  array<string, string>  $bioI18n
     * @param  array<int, string>  $specialties
     * @param  array<string, string>  $socialLinks
     */
    public function __construct(
        public string $publicId,
        public string $name,
        public ?string $headline,
        public ?string $bio,
        public array $headlineI18n,
        public array $bioI18n,
        public array $specialties,
        public array $socialLinks,
        public ?string $website,
        public ?string $profilePhoto,
        public ?string $coverPhoto,
        public ?string $avatarPath,
        public int $displayOrder,
    ) {}
}
