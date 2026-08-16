<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Database\Factories\ProductFactory;
use App\Contexts\Commerce\Enums\AccessDurationType;
use App\Contexts\Commerce\Enums\CertificateExpiryType;
use App\Contexts\Commerce\Enums\CertificateRefundPolicy;
use App\Contexts\Commerce\Enums\CompanyCertificateBranding;
use App\Contexts\Commerce\Enums\ProductAudience;
use App\Contexts\Commerce\Enums\ProductStatus;
use App\Contexts\Commerce\Enums\ProductType;
use App\Contexts\Commerce\Enums\RefundAccessPolicy;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Enums\SeatReassignmentPolicy;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasSlug;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A purchasable product. Grants one or more courses on purchase (single course or bundle).
 *
 * @property int $id
 * @property string $public_id
 * @property string $title
 * @property string|null $description
 * @property string|null $image_path
 * @property ProductType $type
 * @property ProductStatus $status
 *                                 The policy columns below carry database defaults, so a PERSISTED product always has them. They are
 *                                 typed nullable because a model that has not been saved yet (or was hydrated before the policy
 *                                 migration) holds nothing in memory — reading one must not fatal a response.
 * @property ProductAudience|null $audience
 * @property AccessDurationType|null $access_duration_type
 * @property int|null $access_duration_value
 * @property Carbon|null $access_ends_at
 * @property bool $certificate_enabled
 * @property CertificateExpiryType|null $certificate_expiry_type
 * @property int|null $certificate_expiry_value
 * @property Carbon|null $certificate_expires_at
 * @property array<int, int>|null $reminder_offsets_days
 * @property array<int, string>|null $reminder_channels
 * @property RefundAccessPolicy|null $refund_access_policy
 * @property CertificateRefundPolicy|null $certificate_refund_policy
 * @property SeatMode|null $seat_mode
 * @property int|null $default_seat_count
 * @property SeatReassignmentPolicy|null $seat_reassignment_policy
 * @property int|null $reassignment_progress_threshold
 * @property CompanyCertificateBranding|null $company_certificate_branding
 * @property bool $employee_access_expires_with_purchase
 * @property-read Collection<int, ProductPrice> $prices
 * @property-read Collection<int, Course> $courses
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasPublicId;
    use HasSlug;
    use HasTranslations;
    use SoftDeletes;

    protected $fillable = [
        'type', 'title', 'title_i18n', 'slug', 'description', 'description_i18n', 'image_path', 'status',
        // Commercial policy (admin-controlled; see the add_commercial_policy_to_products migration).
        'audience',
        'access_duration_type', 'access_duration_value', 'access_ends_at',
        'certificate_enabled', 'certificate_expiry_type', 'certificate_expiry_value', 'certificate_expires_at',
        'reminder_offsets_days', 'reminder_channels',
        'refund_access_policy', 'certificate_refund_policy',
        'seat_mode', 'default_seat_count', 'seat_reassignment_policy', 'reassignment_progress_threshold',
        'company_certificate_branding', 'employee_access_expires_with_purchase',
    ];

    /** @var array<int, string> */
    protected array $translatable = ['title_i18n', 'description_i18n'];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'title_i18n' => 'array',
            'description_i18n' => 'array',
            'audience' => ProductAudience::class,
            'access_duration_type' => AccessDurationType::class,
            'access_duration_value' => 'integer',
            'access_ends_at' => 'datetime',
            'certificate_enabled' => 'boolean',
            'certificate_expiry_type' => CertificateExpiryType::class,
            'certificate_expiry_value' => 'integer',
            'certificate_expires_at' => 'datetime',
            'reminder_offsets_days' => 'array',
            'reminder_channels' => 'array',
            'refund_access_policy' => RefundAccessPolicy::class,
            'certificate_refund_policy' => CertificateRefundPolicy::class,
            'seat_mode' => SeatMode::class,
            'default_seat_count' => 'integer',
            'seat_reassignment_policy' => SeatReassignmentPolicy::class,
            'reassignment_progress_threshold' => 'integer',
            'company_certificate_branding' => CompanyCertificateBranding::class,
            'employee_access_expires_with_purchase' => 'boolean',
        ];
    }

    public function slugSource(): string
    {
        return 'title';
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'product_courses');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === ProductStatus::Active;
    }

    /**
     * Products a company may buy (seat-bearing), for the company catalogue.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeForCompanies(Builder $query): Builder
    {
        return $query->whereIn('audience', [ProductAudience::Company->value, ProductAudience::Both->value]);
    }

    /**
     * Products an individual may buy for themselves.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeForIndividuals(Builder $query): Builder
    {
        return $query->whereIn('audience', [ProductAudience::Individual->value, ProductAudience::Both->value]);
    }

    /** The default price row, or the first one when none is flagged default. */
    public function defaultPrice(): ?ProductPrice
    {
        $prices = $this->relationLoaded('prices') ? $this->prices : $this->prices()->get();

        $price = $prices->firstWhere('is_default', true) ?? $prices->first();

        return $price instanceof ProductPrice ? $price : null;
    }

    /**
     * When access bought at `$purchasedAt` ends. Null means it never expires.
     */
    public function accessEndsAfter(Carbon $purchasedAt): ?Carbon
    {
        // An unset policy behaves as the column default (lifetime), which never expires.
        return ($this->access_duration_type ?? AccessDurationType::Lifetime)->resolveEnd(
            $purchasedAt,
            $this->access_duration_value,
            $this->access_ends_at,
        );
    }

    /**
     * When a certificate issued at `$issuedAt` stops being valid. Null means it never expires.
     */
    public function certificateExpiresAfter(Carbon $issuedAt): ?Carbon
    {
        if (! $this->certificate_enabled) {
            return null;
        }

        // An unset policy behaves as the column default (never expires).
        return ($this->certificate_expiry_type ?? CertificateExpiryType::None)->resolveExpiry(
            $issuedAt,
            $this->certificate_expiry_value,
            $this->certificate_expires_at,
        );
    }

    /**
     * Ids of the courses this product grants. Scalars only, so callers never hold a Catalog model.
     *
     * @return list<int>
     */
    public function courseIds(): array
    {
        $courses = $this->relationLoaded('courses') ? $this->courses : $this->courses()->get();

        return $courses->map(fn ($c): int => (int) $c->getKey())->values()->all();
    }

    /**
     * Reminder lead times in days, largest first so a caller sends the earliest notice first.
     *
     * @return list<int>
     */
    public function reminderOffsets(): array
    {
        $offsets = array_values(array_filter(
            array_map('intval', $this->reminder_offsets_days ?? []),
            static fn (int $d): bool => $d > 0,
        ));
        rsort($offsets);

        return array_values(array_unique($offsets));
    }

    /**
     * Configured reminder channels.
     *
     * @return list<string>
     */
    public function reminderChannels(): array
    {
        return array_values(array_filter(
            $this->reminder_channels ?? [],
            static fn (string $c): bool => $c !== '',
        ));
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
