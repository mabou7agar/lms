<?php

namespace App\Domains\Authoring\Http\Resources;

use App\Domains\Authoring\Models\ContentVersion;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property ContentVersion $resource
 *
 * Exposes version metadata + a snapshot SUMMARY only. It never returns the raw snapshot body or any
 * internal database id — public_id is the sole external identifier.
 */
class ContentVersionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $metadata = $this->resource->metadata ?? [];
        $counts = is_array($metadata['counts'] ?? null)
            ? $metadata['counts']
            : ['modules' => 0, 'sections' => 0, 'lessons' => 0, 'blocks' => 0];

        $source = $this->resource->sourceVersion;

        return [
            'id' => $this->resource->public_id,
            'version_number' => $this->resource->version_number,
            'label' => $this->resource->label,
            'reason' => $this->resource->reason->value,
            'checksum' => $this->resource->checksum,
            'schema_version' => $this->resource->snapshot_schema_version,
            'created_by' => $this->resource->created_by,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'source' => $source === null ? null : [
                'id' => $source->public_id,
                'version_number' => $source->version_number,
                // Present only on forks: the version came from a different course.
                'from_other_course' => (int) $source->course_id !== (int) $this->resource->course_id,
            ],
            'summary' => [
                'modules' => (int) ($counts['modules'] ?? 0),
                'sections' => (int) ($counts['sections'] ?? 0),
                'lessons' => (int) ($counts['lessons'] ?? 0),
                'blocks' => (int) ($counts['blocks'] ?? 0),
            ],
        ];
    }
}
