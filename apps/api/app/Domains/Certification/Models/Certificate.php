<?php

namespace App\Domains\Certification\Models;

use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Database\Factories\CertificateFactory;
use App\Domains\Certification\Enums\CertificateStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $user_id
 */
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'course_id', 'enrollment_id', 'template_id', 'number', 'verification_code',
        'status', 'signature_name', 'signature_title', 'signature_hash', 'pdf_path', 'pdf_generated_at',
        'metadata', 'issued_at', 'revoked_at', 'reissued_at',
    ];

    protected $hidden = ['pdf_path']; // storage path is never serialized

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'metadata' => 'array',
            'pdf_generated_at' => 'datetime',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'reissued_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Certificate holder. Resolved via auth config (not a concrete Identity import) so
     * Certification keeps no compile-time dependency on the Identity context.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel, 'user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'template_id');
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('status', CertificateStatus::Issued->value);
    }

    public function isValid(): bool
    {
        return $this->status === CertificateStatus::Issued;
    }

    protected static function newFactory(): CertificateFactory
    {
        return CertificateFactory::new();
    }
}
