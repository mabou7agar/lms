<?php

namespace App\Platform\Media\Models;

use App\Platform\Media\Database\Factories\MediaAssetFactory;
use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenantNullable;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * P2/W04 - A single media asset and its ingestion lifecycle. Rows are shaped only by services via
 * forceFill() (nothing is mass-assignable), so a request body can never set a storage key, provider
 * ref or status. Cross-context ids (created_by, course_id) are plain scalars — no Eloquent link.
 *
 * @property int $id
 * @property string $public_id
 * @property MediaType $type
 * @property MediaStatus $status
 * @property MediaProvider $provider
 * @property MediaPurpose $purpose
 * @property MediaVisibility $visibility
 * @property int $created_by
 * @property int|null $course_id
 * @property int|null $folder_id
 * @property int|null $organization_id
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $duration_seconds
 * @property int|null $width
 * @property int|null $height
 * @property string|null $provider_ref
 * @property string|null $playback_id
 * @property string|null $storage_key
 * @property string|null $thumbnail_ref
 * @property int $processing_progress
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $metadata
 * @property string $idempotency_key
 * @property string|null $upload_token
 * @property Carbon|null $upload_token_expires_at
 * @property Carbon|null $upload_token_consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, MediaAttachment> $attachments
 * @property-read Collection<int, MediaCaption> $captions
 * @property-read Collection<int, MediaVariant> $variants
 */
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    // T1 Option-N tenancy (SHARED-OR-OWNED / NULLABLE): adds SharedOrOwnedTenantScope so a resolved
    // tenant sees global rows (organization_id IS NULL) PLUS its own rows and NEVER another org's
    // private assets, and stamps organization_id on create ONLY when a tenant is resolved (else the
    // asset is created GLOBAL/NULL). The tenant is derived server-side from the resolved TenantContext
    // (users.organization_id), NEVER from client input. organization_id is additionally $guarded, so a
    // forged organization_id in a request payload can never be mass-assigned onto an asset.
    use BelongsToTenantNullable;

    use HasPublicId;
    use SoftDeletes;

    protected $table = 'media_assets';

    /** Written only through services via forceFill(); nothing is mass-assignable, and the tenant column is never fillable. */
    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'status' => MediaStatus::class,
            'provider' => MediaProvider::class,
            'purpose' => MediaPurpose::class,
            'visibility' => MediaVisibility::class,
            'created_by' => 'integer',
            'course_id' => 'integer',
            'folder_id' => 'integer',
            'organization_id' => 'integer',
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'processing_progress' => 'integer',
            'metadata' => 'array',
            'upload_token_expires_at' => 'datetime',
            'upload_token_consumed_at' => 'datetime',
        ];
    }

    /** @return HasMany<MediaAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }

    /**
     * Optional organizational folder (Phase 8 / D1); null = root.
     *
     * @return BelongsTo<MediaFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /** @return HasMany<MediaCaption, $this> */
    public function captions(): HasMany
    {
        return $this->hasMany(MediaCaption::class);
    }

    /**
     * D6 - Derived image variants (thumbnail/small/medium/large/webp...). Populated by the async image
     * pipeline; empty for non-image assets. Never includes the original object.
     *
     * @return HasMany<MediaVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    /** A finalize token is usable only while unconsumed and unexpired. */
    public function hasLiveUploadToken(): bool
    {
        return $this->upload_token !== null
            && $this->upload_token_consumed_at === null
            && $this->upload_token_expires_at !== null
            && $this->upload_token_expires_at->isFuture();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, int $actorId): Builder
    {
        return $query->where('created_by', $actorId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where('course_id', $courseId);
    }

    protected static function newFactory(): MediaAssetFactory
    {
        return MediaAssetFactory::new();
    }
}
