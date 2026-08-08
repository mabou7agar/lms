<?php

namespace App\Platform\Media\Imaging;

use App\Platform\Media\Imaging\Data\VariantSpec;
use App\Platform\Media\Models\MediaAsset;

/**
 * Phase A / D6 - Resolves the ordered list of VariantSpecs to derive for an asset. A surface may be
 * passed explicitly (e.g. 'course_thumbnail', 'instructor_avatar'); otherwise it is inferred from the
 * asset's upload PURPOSE via config('media.images.purpose_surface'), falling back to the 'default' set.
 * Pure config translation — no I/O, no GD — so it is trivially unit-testable.
 */
class VariantPlanner
{
    /**
     * @return list<VariantSpec>
     */
    public function for(MediaAsset $asset, ?string $surface = null): array
    {
        return $this->forSurface($surface ?? $this->surfaceForPurpose($asset->purpose->value));
    }

    /**
     * @return list<VariantSpec>
     */
    public function forSurface(string $surface): array
    {
        /** @var array<string, array<string, array<string, mixed>>> $sets */
        $sets = (array) config('media.images.variant_sets', []);

        /** @var array<string, array<string, mixed>> $set */
        $set = $sets[$surface] ?? $sets['default'] ?? [];

        /** @var array<string, int> $qualityDefaults */
        $qualityDefaults = (array) config('media.images.quality', []);

        $specs = [];

        foreach ($set as $key => $definition) {
            $specs[] = VariantSpec::fromConfig((string) $key, (array) $definition, $qualityDefaults);
        }

        return $specs;
    }

    /** Map an asset's upload purpose to a surface set; unknown purposes fall back to 'default'. */
    public function surfaceForPurpose(string $purpose): string
    {
        /** @var array<string, string> $map */
        $map = (array) config('media.images.purpose_surface', []);

        return $map[$purpose] ?? 'default';
    }
}
