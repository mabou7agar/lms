<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One user's ongoing subscription to a plan. Its lifecycle is a state machine over
 * SubscriptionStatus (trialing → active → past_due → grace → expired, plus canceled/paused);
 * current_period_* bound the paid-through window, trial_ends_at/grace_ends_at add the trial and
 * dunning clocks, and cancel_at_period_end schedules a soft cancellation that is finalised when the
 * period rolls over. Money (amount_minor) is integer minor units only. The user relation is bound to
 * the configured auth model so no cross-context Eloquent class is imported.
 *
 * @property-read SubscriptionPlan|null $plan
 * @property-read Collection<int, SubscriptionChange> $changes
 * A subscription's SUBSCRIBER is either an individual user (user_id) OR a CRM organization
 * (organization_id) — exactly one is set (invariant enforced in the Actions layer). An organization
 * subscription additionally carries a purchased seat capacity (seats) and the CRM seat pool it
 * provisioned (seat_pool_id); the recurring charge is still the plan's price, so the lifecycle
 * (renewal/upgrade/cancel/grace/expire) is reused unchanged for both subscriber kinds.
 *
 * @property string $public_id
 * @property int $id
 * @property int|null $user_id
 * @property int|null $organization_id
 * @property int|null $seats
 * @property int|null $seat_pool_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $grace_ends_at
 * @property Carbon|null $canceled_at
 * @property bool $cancel_at_period_end
 * @property string $currency
 * @property int $amount_minor
 * @property string|null $provider
 * @property string|null $provider_reference
 */
class Subscription extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id',
        'organization_id',
        'seats',
        'seat_pool_id',
        'plan_id',
        'status',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'grace_ends_at',
        'canceled_at',
        'cancel_at_period_end',
        'currency',
        'amount_minor',
        'provider',
        'provider_reference',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'amount_minor' => 'integer',
            'organization_id' => 'integer',
            'seats' => 'integer',
            'seat_pool_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * Append-only audit trail of lifecycle transitions (created/renewal/upgrade/…), newest last.
     * Read-only: rows are written by the domain Actions; this relation only exposes them for display.
     *
     * @return HasMany<SubscriptionChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class);
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = (string) config('auth.providers.users.model', 'App\\Models\\User');

        return $this->belongsTo($model, 'user_id');
    }

    /** Typed status accessor for PHPStan-clean reads. */
    public function statusEnum(): SubscriptionStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof SubscriptionStatus
            ? $status
            : SubscriptionStatus::from((string) $status);
    }

    /**
     * The string values of every status that grants access, derived from the enum so it tracks
     * SubscriptionStatus::grantsAccess() without duplicating the list.
     *
     * @return list<string>
     */
    public static function accessGrantingStatusValues(): array
    {
        return array_values(array_map(
            fn (SubscriptionStatus $status) => $status->value,
            array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $status) => $status->grantsAccess()),
        ));
    }

    /**
     * The owning user id, or 0 for an organization subscription. Kept int-typed so the existing
     * user-subscription callers and the scalar-id lifecycle events are unaffected.
     */
    public function userId(): int
    {
        return (int) $this->getAttribute('user_id');
    }

    /** The owning CRM organization id, or null for an individual (user) subscription. */
    public function organizationId(): ?int
    {
        $value = $this->getAttribute('organization_id');

        return $value === null ? null : (int) $value;
    }

    /** Purchased seat capacity for an organization subscription (null for a user subscription). */
    public function seats(): ?int
    {
        $value = $this->getAttribute('seats');

        return $value === null ? null : (int) $value;
    }

    /** The provisioned CRM seat pool id backing an organization subscription, if any. */
    public function seatPoolId(): ?int
    {
        $value = $this->getAttribute('seat_pool_id');

        return $value === null ? null : (int) $value;
    }

    /** Whether the subscriber is a CRM organization (vs an individual user). */
    public function isOrganization(): bool
    {
        return $this->getAttribute('organization_id') !== null;
    }

    public function planId(): int
    {
        return (int) $this->getAttribute('plan_id');
    }

    public function amountMinor(): int
    {
        return (int) $this->getAttribute('amount_minor');
    }

    public function currency(): string
    {
        return (string) $this->getAttribute('currency');
    }

    public function currentPeriodStart(): ?Carbon
    {
        $value = $this->getAttribute('current_period_start');

        return $value instanceof Carbon ? $value : null;
    }

    public function currentPeriodEnd(): ?Carbon
    {
        $value = $this->getAttribute('current_period_end');

        return $value instanceof Carbon ? $value : null;
    }

    public function trialEndsAt(): ?Carbon
    {
        $value = $this->getAttribute('trial_ends_at');

        return $value instanceof Carbon ? $value : null;
    }

    public function graceEndsAt(): ?Carbon
    {
        $value = $this->getAttribute('grace_ends_at');

        return $value instanceof Carbon ? $value : null;
    }

    public function cancelAtPeriodEnd(): bool
    {
        return (bool) $this->getAttribute('cancel_at_period_end');
    }

    /**
     * The instant access lapses for the current status: the grace clock while in grace/past_due,
     * otherwise the paid-through period end.
     */
    public function effectiveEnd(): ?Carbon
    {
        $status = $this->statusEnum();

        if ($status === SubscriptionStatus::Grace || $status === SubscriptionStatus::PastDue) {
            return $this->graceEndsAt() ?? $this->currentPeriodEnd();
        }

        return $this->currentPeriodEnd();
    }

    /**
     * Whether the subscription grants access right now: its status must grant access and its
     * effective window must not have elapsed.
     */
    public function isActiveNow(): bool
    {
        if (! $this->statusEnum()->grantsAccess()) {
            return false;
        }

        $end = $this->effectiveEnd();

        return $end === null || $end->isFuture();
    }
}
