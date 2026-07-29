<?php

namespace App\Domains\Authoring\Models;

use App\Domains\Authoring\Database\Factories\ContentVersionFactory;
use App\Domains\Authoring\Enums\VersionReason;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * P2/W03 - An immutable authoring snapshot for a course. Rows are append-only: once written they
 * can never be updated or deleted (enforced here AND by a DB trigger). Carries only scalars + the
 * opaque JSON snapshot; it holds no live Eloquent references.
 *
 * @property int $course_id
 * @property int $version_number
 * @property string|null $label
 * @property VersionReason $reason
 * @property int|null $source_version_id
 * @property int|null $source_course_id
 * @property array<string, mixed> $snapshot
 * @property int $snapshot_schema_version
 * @property string $checksum
 * @property int|null $created_by
 * @property array<string, mixed>|null $metadata
 * @property string $public_id
 * @property Carbon|null $created_at
 * @property-read ContentVersion|null $sourceVersion
 */
class ContentVersion extends Model
{
    /** @use HasFactory<ContentVersionFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'content_versions';

    /** Immutable: no updated_at column and no update path. */
    public const UPDATED_AT = null;

    /**
     * Written once by ContentVersionWriter via explicit assignment; nothing is mass-assignable so a
     * request body can never shape a version row.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'version_number' => 'integer',
            'reason' => VersionReason::class,
            'source_version_id' => 'integer',
            'source_course_id' => 'integer',
            'snapshot' => 'array',
            'snapshot_schema_version' => 'integer',
            'created_by' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * App-layer immutability guard (the DB trigger is the second line of defence): a saved version
     * can never be updated or deleted.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Content versions are immutable and cannot be updated.');
        });
        static::deleting(function (): void {
            throw new RuntimeException('Content versions are immutable and cannot be deleted.');
        });
    }

    /** @return BelongsTo<self, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_version_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where('course_id', $courseId);
    }

    protected static function newFactory(): ContentVersionFactory
    {
        return ContentVersionFactory::new();
    }
}
