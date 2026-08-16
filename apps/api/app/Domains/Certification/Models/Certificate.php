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
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property int $course_id
 * @property string $public_id
 * @property string $number
 * @property string $verification_code
 * @property CertificateStatus $status
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property int|null $organization_id
 * @property string|null $company_name
 * @property string|null $company_logo_url
 * @property string|null $branding_mode
 */
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'course_id', 'enrollment_id', 'template_id', 'template_version', 'number', 'verification_code',
        'status', 'signature_name', 'signature_title', 'signature_hash', 'pdf_path', 'pdf_generated_at',
        'metadata', 'rendered_snapshot', 'issued_at', 'expires_at', 'revoked_at', 'reissued_at',
        // Commercial context, resolved at issue and snapshotted so the credential still reads
        // correctly after the company rebrands or the product is retired.
        'organization_id', 'company_name', 'company_logo_url', 'branding_mode',
    ];

    protected $hidden = ['pdf_path']; // storage path is never serialized

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'metadata' => 'array',
            'rendered_snapshot' => 'array',
            'template_version' => 'integer',
            'pdf_generated_at' => 'datetime',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
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
        return $this->status === CertificateStatus::Issued && ! $this->hasExpired();
    }

    /** Has the credential's own validity window closed? Null expiry means it never lapses. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The status a reader should see, folding in an expiry the clock has passed. Revocation outranks
     * expiry — a withdrawn credential is withdrawn regardless of its dates.
     */
    public function effectiveStatus(): CertificateStatus
    {
        if ($this->status === CertificateStatus::Revoked) {
            return CertificateStatus::Revoked;
        }

        return $this->hasExpired() ? CertificateStatus::Expired : $this->status;
    }

    /** Does this credential carry a company's marks? */
    public function isCompanyBranded(): bool
    {
        return $this->organization_id !== null
            && $this->branding_mode !== null
            && $this->branding_mode !== 'helbaron_only';
    }

    /**
     * Valid certificates whose window closes inside the next `$days` days — the reminder sweep's
     * working set.
     *
     * @param  Builder<Certificate>  $query
     * @return Builder<Certificate>
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->valid()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    protected static function newFactory(): CertificateFactory
    {
        return CertificateFactory::new();
    }
}
