<?php

namespace App\Domains\Authoring\Database\Factories;

use App\Domains\Authoring\Enums\VersionReason;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Snapshots\SnapshotSerializer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentVersion>
 *
 * Produces a minimal, self-consistent version (empty snapshot + matching checksum). Real versions
 * are created through ContentVersioningService; this exists so tests can seed history cheaply.
 */
class ContentVersionFactory extends Factory
{
    protected $model = ContentVersion::class;

    public function definition(): array
    {
        $snapshot = [
            'schema_version' => SnapshotSerializer::SCHEMA_VERSION,
            'course_id' => 0,
            'modules' => [],
            'sections' => [],
        ];

        return [
            'course_id' => 0,
            'version_number' => 1,
            'label' => null,
            'reason' => VersionReason::Manual->value,
            'source_version_id' => null,
            'source_course_id' => null,
            'snapshot' => $snapshot,
            'snapshot_schema_version' => SnapshotSerializer::SCHEMA_VERSION,
            'checksum' => SnapshotSerializer::checksum($snapshot),
            'created_by' => null,
            'metadata' => ['counts' => SnapshotSerializer::counts($snapshot)],
        ];
    }
}
