<?php

namespace App\Domains\Authoring\Services;

use App\Domains\Authoring\Enums\VersionReason;
use App\Domains\Authoring\Exceptions\VersionConflictException;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Snapshots\SnapshotSerializer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * P2/W03 - Persists one immutable ContentVersion row with a safe, gap-free version number.
 *
 * Concurrency: on PostgreSQL a per-course transaction-scoped advisory lock serialises writers for
 * the same course, so `max(version_number) + 1` cannot race. The unique (course_id, version_number)
 * index is the backstop — a losing writer surfaces as a clean 409 VersionConflict rather than a
 * duplicate. MUST run inside the caller's transaction (the advisory lock releases on commit).
 */
final class ContentVersionWriter
{
    private const ADVISORY_LOCK_NAMESPACE = 4242;

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{0: ContentVersion, 1: bool} [version, wasDeduplicated]
     */
    public function write(
        int $courseId,
        array $snapshot,
        VersionReason $reason,
        ?int $sourceVersionId,
        ?int $sourceCourseId,
        ?int $actorId,
        ?string $label,
        bool $dedupe,
    ): array {
        $checksum = SnapshotSerializer::checksum($snapshot);

        $this->lockCourse($courseId);

        if ($dedupe) {
            $latest = ContentVersion::query()
                ->forCourse($courseId)
                ->orderByDesc('version_number')
                ->first();

            if ($latest !== null && $latest->checksum === $checksum) {
                return [$latest, true];
            }
        }

        $next = (int) ContentVersion::query()->forCourse($courseId)->max('version_number') + 1;

        $version = new ContentVersion;
        $version->course_id = $courseId;
        $version->version_number = $next;
        $version->label = $label;
        $version->reason = $reason;
        $version->source_version_id = $sourceVersionId;
        $version->source_course_id = $sourceCourseId;
        $version->snapshot = $snapshot;
        $version->snapshot_schema_version = (int) ($snapshot['schema_version'] ?? SnapshotSerializer::SCHEMA_VERSION);
        $version->checksum = $checksum;
        $version->created_by = $actorId;
        $version->metadata = ['counts' => SnapshotSerializer::counts($snapshot)];

        try {
            $version->save();
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new VersionConflictException(details: ['course_id' => $courseId, 'version_number' => $next]);
            }
            throw $e;
        }

        return [$version, false];
    }

    private function lockCourse(int $courseId): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [self::ADVISORY_LOCK_NAMESPACE, $courseId]);
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // PostgreSQL unique_violation SQLSTATE.
        return ($e->getCode() === '23505')
            || str_contains($e->getMessage(), 'content_versions_course_id_version_number_unique');
    }
}
