<?php

namespace App\Platform\Branding\Models;

use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A single organization's white-label OVERRIDE of the global BrandSetting. Each JSON group mirrors
 * the global model; stored values are the only ones the org has customised. The public payload for
 * an org is produced by deep-merging this row's DEFINED values OVER the global defaults-merged
 * payload (see mergedOver) — the org overrides exactly the fields it set and inherits the rest from
 * the global brand. Presentation only: no secrets, safe to expose publicly.
 *
 * This model is NOT globally tenant-scoped: the brand resolver must read an org's override by
 * organization_id for an unauthenticated public request (resolution by Host), so isolation is
 * enforced by the policy/controller on the write side (own-org only), exactly like SsoDomainMapping.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property array<string, mixed>|null $identity
 * @property array<string, mixed>|null $logos
 * @property array<string, mixed>|null $theme
 * @property array<string, mixed>|null $email
 * @property array<string, mixed>|null $certificate
 */
class OrganizationBrandSetting extends Model
{
    use HasPublicId;

    protected $fillable = ['organization_id', 'identity', 'logos', 'theme', 'email', 'certificate'];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'identity' => 'array',
            'logos' => 'array',
            'theme' => 'array',
            'email' => 'array',
            'certificate' => 'array',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Overlay this org's DEFINED values onto the already-resolved GLOBAL public payload. Only keys the
     * org actually set win; everything else stays the global value. Media keys (logos + certificate
     * images) are (re)resolved to public URLs after merging — a global value already resolved to a URL
     * passes through unchanged, an org-set MediaAsset reference is resolved, and anything not
     * resolvable (empty / private / missing) collapses to '' so the payload keeps its complete,
     * render-ready shape and no raw reference ever leaks.
     *
     * @param  array<string, array<string, mixed>>  $global
     * @return array<string, array<string, mixed>>
     */
    public function mergedOver(array $global): array
    {
        $logos = self::deepMergeDefined($global['logos'] ?? [], $this->logos);
        $certificate = self::deepMergeDefined($global['certificate'] ?? [], $this->certificate);

        return [
            'identity' => self::deepMergeDefined($global['identity'] ?? [], $this->identity),
            'logos' => self::resolveMediaKeys($logos, array_keys($global['logos'] ?? [])),
            'theme' => self::deepMergeDefined($global['theme'] ?? [], $this->theme),
            'email' => self::deepMergeDefined($global['email'] ?? [], $this->email),
            'certificate' => self::resolveMediaKeys($certificate, ['background', 'logo', 'signature', 'stamp']),
        ];
    }

    /**
     * Resolve the given media-reference keys of a branding group to public URLs, coalescing a null
     * resolution back to '' so the group stays a complete map. Non-string / absent keys are untouched.
     *
     * @param  array<string, mixed>  $group
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private static function resolveMediaKeys(array $group, array $keys): array
    {
        $resolver = app(PublicAssetUrlResolver::class);

        foreach ($keys as $key) {
            if (! array_key_exists($key, $group) || ! is_string($group[$key])) {
                continue;
            }

            $group[$key] = $resolver->resolve($group[$key]) ?? '';
        }

        return $group;
    }

    /**
     * Recursively overlay $override onto $default, skipping null values so the default always wins for
     * anything the org has not set. Associative-map semantics (branding groups are maps, not lists).
     *
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    private static function deepMergeDefined(array $default, ?array $override): array
    {
        if ($override === null) {
            return $default;
        }

        $result = $default;

        foreach ($override as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && isset($default[$key]) && is_array($default[$key])) {
                $result[$key] = self::deepMergeDefined($default[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
