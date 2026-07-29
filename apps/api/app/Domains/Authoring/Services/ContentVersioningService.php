<?php

namespace App\Domains\Authoring\Services;

use App\Domains\Authoring\Enums\VersionReason;
use App\Domains\Authoring\Events\ContentVersionCloned;
use App\Domains\Authoring\Events\ContentVersionCreated;
use App\Domains\Authoring\Events\ContentVersionForked;
use App\Domains\Authoring\Events\ContentVersionRestored;
use App\Domains\Authoring\Events\ContentVersionRolledBack;
use App\Domains\Authoring\Exceptions\SnapshotChecksumMismatchException;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Snapshots\SnapshotRestorer;
use App\Domains\Authoring\Snapshots\SnapshotSerializer;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * P2/W03 - The single entry point for every content-version operation. Each write runs in one
 * transaction, so a failure anywhere leaves the draft and the history untouched.
 */
final class ContentVersioningService
{
    public function __construct(
        private readonly SnapshotSerializer $serializer,
        private readonly SnapshotRestorer $restorer,
        private readonly ContentVersionWriter $writer,
        private readonly AuditLogger $audit,
    ) {}

    /** Immutable snapshot of the current draft. Deduplicated against the latest unless forced. */
    public function createSnapshot(int $courseId, ?int $actorId, ?string $label, bool $force): ContentVersion
    {
        return DB::transaction(function () use ($courseId, $actorId, $label, $force): ContentVersion {
            $snapshot = $this->serializer->capture($courseId);

            [$version, $deduped] = $this->writer->write(
                $courseId, $snapshot, VersionReason::Manual, null, null, $actorId, $label, ! $force,
            );

            if (! $deduped) {
                $this->audit->log('authoring.version.created', $version, ['reason' => 'manual'], $actorId);
                ContentVersionCreated::dispatch($courseId, (int) $version->id, (int) $version->version_number, $actorId);
            }

            return $version;
        });
    }

    /**
     * Replace the current draft with a historical version, after first snapshotting the current
     * draft as a safety version. Returns the safety version that was created.
     */
    public function restoreDraft(ContentVersion $version, ?int $actorId): ContentVersion
    {
        $this->assertIntegrity($version);

        return DB::transaction(function () use ($version, $actorId): ContentVersion {
            $courseId = (int) $version->course_id;

            [$safety] = $this->writer->write(
                $courseId, $this->serializer->capture($courseId), VersionReason::Safety,
                null, null, $actorId, 'Safety snapshot before restore', false,
            );

            $this->restorer->restore($courseId, $version->snapshot, regenerateIds: false);

            $this->audit->log('authoring.version.restored', $version, [
                'safety_version_id' => (int) $safety->id,
            ], $actorId);
            ContentVersionRestored::dispatch($courseId, (int) $version->id, (int) $safety->id, $actorId);

            return $safety;
        });
    }

    /** Promote an older version to a new current version, applying its content to the draft. */
    public function rollback(ContentVersion $version, ?int $actorId, ?string $label): ContentVersion
    {
        $this->assertIntegrity($version);

        return DB::transaction(function () use ($version, $actorId, $label): ContentVersion {
            $courseId = (int) $version->course_id;

            $this->restorer->restore($courseId, $version->snapshot, regenerateIds: false);

            [$new] = $this->writer->write(
                $courseId, $version->snapshot, VersionReason::Rollback,
                (int) $version->id, null, $actorId, $label ?? "Rollback to v{$version->version_number}", false,
            );

            $this->audit->log('authoring.version.rolled_back', $new, [
                'source_version_id' => (int) $version->id,
            ], $actorId);
            ContentVersionRolledBack::dispatch($courseId, (int) $new->id, (int) $version->id, $actorId);

            return $new;
        });
    }

    /** Copy a version within the same course, preserving source attribution. Draft is untouched. */
    public function clone(ContentVersion $version, ?int $actorId, ?string $label): ContentVersion
    {
        $this->assertIntegrity($version);

        return DB::transaction(function () use ($version, $actorId, $label): ContentVersion {
            $courseId = (int) $version->course_id;

            [$new] = $this->writer->write(
                $courseId, $version->snapshot, VersionReason::Clone,
                (int) $version->id, null, $actorId, $label ?? "Clone of v{$version->version_number}", false,
            );

            $this->audit->log('authoring.version.cloned', $new, [
                'source_version_id' => (int) $version->id,
            ], $actorId);
            ContentVersionCloned::dispatch($courseId, (int) $new->id, (int) $version->id, $actorId);

            return $new;
        });
    }

    /**
     * Materialise a version as the draft of ANOTHER course with fresh identifiers, and record a fork
     * version there. The destination draft carries no source foreign keys.
     */
    public function fork(ContentVersion $source, int $destinationCourseId, ?int $actorId, ?string $label): ContentVersion
    {
        $this->assertIntegrity($source);

        return DB::transaction(function () use ($source, $destinationCourseId, $actorId, $label): ContentVersion {
            $this->restorer->restore($destinationCourseId, $source->snapshot, regenerateIds: true);

            // Capture the freshly materialised destination content — new public_ids, destination
            // course_id, no source references — so the fork's snapshot reflects destination reality.
            $destinationSnapshot = $this->serializer->capture($destinationCourseId);

            [$new] = $this->writer->write(
                $destinationCourseId, $destinationSnapshot, VersionReason::Fork,
                (int) $source->id, (int) $source->course_id, $actorId,
                $label ?? "Fork of v{$source->version_number}", false,
            );

            $this->audit->log('authoring.version.forked', $new, [
                'source_course_id' => (int) $source->course_id,
                'source_version_id' => (int) $source->id,
            ], $actorId);
            ContentVersionForked::dispatch(
                (int) $source->course_id, (int) $source->id, $destinationCourseId, (int) $new->id, $actorId,
            );

            return $new;
        });
    }

    /** A stored snapshot must still hash to its stored checksum before it is ever applied. */
    private function assertIntegrity(ContentVersion $version): void
    {
        if (SnapshotSerializer::checksum($version->snapshot) !== $version->checksum) {
            throw new SnapshotChecksumMismatchException(details: [
                'version' => (string) $version->public_id,
            ]);
        }
    }
}
