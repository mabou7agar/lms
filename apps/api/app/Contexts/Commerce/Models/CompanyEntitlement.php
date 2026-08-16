<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Enums\CompanyEntitlementStatus;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Enums\SeatReassignmentPolicy;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A pool of course access an organization bought outright, created when a company order is fulfilled
 * and handed out to employees by their manager.
 *
 * The policy columns are a snapshot of the product as it was sold, so an admin editing the product
 * afterwards never rewrites a customer's terms. Everything here is nullable for the same reason the
 * product's policy columns are: a row that has not been persisted holds nothing in memory, and
 * reading one must not fatal a response.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $order_id
 * @property int $product_id
 * @property int|null $seats_purchased
 * @property int $seats_used
 * @property Carbon|null $access_starts_at
 * @property Carbon|null $access_ends_at
 * @property CompanyEntitlementStatus|null $status
 * @property SeatMode $seat_mode
 * @property SeatReassignmentPolicy $seat_reassignment_policy
 * @property int|null $reassignment_progress_threshold
 * @property string|null $company_certificate_branding
 * @property bool $employee_access_expires_with_purchase
 * @property-read Product|null $product
 * @property-read Order|null $order
 * @property-read Collection<int, CompanyEntitlementAssignment> $assignments
 */
class CompanyEntitlement extends Model
{
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'order_id', 'product_id',
        'seats_purchased', 'seats_used',
        'access_starts_at', 'access_ends_at', 'status',
        'seat_mode', 'seat_reassignment_policy', 'reassignment_progress_threshold',
        'company_certificate_branding', 'employee_access_expires_with_purchase',
    ];

    protected function casts(): array
    {
        return [
            'seats_purchased' => 'integer',
            'seats_used' => 'integer',
            'access_starts_at' => 'datetime',
            'access_ends_at' => 'datetime',
            'status' => CompanyEntitlementStatus::class,
            'seat_mode' => SeatMode::class,
            'seat_reassignment_policy' => SeatReassignmentPolicy::class,
            'reassignment_progress_threshold' => 'integer',
            'employee_access_expires_with_purchase' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<CompanyEntitlementAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(CompanyEntitlementAssignment::class);
    }

    /**
     * Assignments that still hold a seat.
     *
     * @return HasMany<CompanyEntitlementAssignment, $this>
     */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('revoked_at');
    }

    /** Unlimited pools are a whole-organization licence: no seat is ever counted against them. */
    public function isUnlimited(): bool
    {
        return $this->seat_mode === SeatMode::Unlimited || $this->seats_purchased === null;
    }

    /** Free capacity. Unlimited pools report null rather than a number that would be a lie. */
    public function seatsAvailable(): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return max(0, (int) $this->seats_purchased - (int) $this->seats_used);
    }

    /** Has the purchased access window elapsed? A pool with no end date never expires. */
    public function hasExpired(): bool
    {
        return $this->access_ends_at !== null && $this->access_ends_at->isPast();
    }

    /**
     * May this pool be assigned from right now? Expiry is evaluated against the clock as well as the
     * stored status, so an entitlement that lapsed since the last write is refused immediately
     * rather than waiting for a job to relabel it.
     */
    public function isAssignable(): bool
    {
        return $this->status === CompanyEntitlementStatus::Active
            && ! $this->hasExpired()
            && ($this->access_starts_at === null || ! $this->access_starts_at->isFuture());
    }

    /** The status a reader should see, folding in an access window that has since elapsed. */
    public function effectiveStatus(): CompanyEntitlementStatus
    {
        $status = $this->status ?? CompanyEntitlementStatus::Active;

        if ($status === CompanyEntitlementStatus::Active && $this->hasExpired()) {
            return CompanyEntitlementStatus::Expired;
        }

        return $status;
    }

    /**
     * The date employee access should carry, or null when it must not expire. A product whose policy
     * says employee access outlives the purchase grants open-ended enrollments.
     */
    public function employeeAccessEndsAt(): ?Carbon
    {
        return $this->employee_access_expires_with_purchase ? $this->access_ends_at : null;
    }

    /**
     * @param  Builder<CompanyEntitlement>  $query
     * @return Builder<CompanyEntitlement>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
