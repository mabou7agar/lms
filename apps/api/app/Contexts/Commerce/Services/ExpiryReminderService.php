<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Enums\CompanyEntitlementStatus;
use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Models\CompanyEntitlementAssignment;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Certification\Contracts\CertificateStatusPort;
use App\Platform\Shared\Enterprise\Contracts\OrganizationLookupPort;
use App\Platform\Shared\Notifications\Contracts\ExpiryNotificationPort;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tells people that something they paid for is about to run out, at the lead times the admin chose.
 *
 * The cadence is the product's `reminder_offsets_days` — "warn me 30 and 7 days ahead" — and a
 * reminder fires on the day the remaining time first drops to or below an offset. Offsets are read
 * LIVE from the product rather than from the purchase snapshot, deliberately: how often to nag is an
 * operational setting an admin should be able to change and see take effect, unlike the seats and
 * dates a customer was actually sold.
 *
 * Nothing here tracks what it has already sent. Every notice carries a dedup key of
 * (kind, reference, recipient, offset), so the notifications table itself is the ledger and running
 * this sweep hourly, daily, or twice by accident produces exactly one notice per offset. That is why
 * the sweep can afford to be this simple.
 *
 * Who hears what:
 *   - a company's OWNERS AND ADMINS hear that the purchase is lapsing, because only they can renew it;
 *   - each SEATED EMPLOYEE hears that their access is ending, because only they can finish in time;
 *   - a CERTIFICATE HOLDER hears that their credential is lapsing.
 *
 * `reminder_channels` may include `banner`, which is not a delivery channel — it is the UI reading
 * the same expiry dates off the purchase and certificate payloads. Only email and in-app are things
 * to send, and channel selection itself belongs to the notification platform.
 */
class ExpiryReminderService extends BaseService
{
    /** Offsets used when a product names none, so a lapsing purchase is never a silent surprise. */
    public const DEFAULT_OFFSETS = [30, 7, 1];

    public function __construct(
        private readonly ExpiryNotificationPort $notifications,
        private readonly OrganizationLookupPort $organizations,
        private readonly CertificateStatusPort $certificates,
    ) {}

    /**
     * Run one sweep. Returns a count per reminder kind, which is what the command reports and the
     * tests assert on.
     *
     * @return array{purchases: int, seats: int, certificates: int}
     */
    public function sweep(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        return [
            'purchases' => $this->sweepCompanyPurchases($now),
            'seats' => $this->sweepSeatAccess($now),
            'certificates' => $this->sweepCertificates($now),
        ];
    }

    /** Company purchases approaching the end of their access window → the people who can renew. */
    private function sweepCompanyPurchases(Carbon $now): int
    {
        $sent = 0;

        foreach ($this->expiringEntitlements($now) as $entitlement) {
            $offset = $this->dueOffset($entitlement->access_ends_at, $this->offsetsFor($entitlement), $now);

            if ($offset === null) {
                continue;
            }

            $recipients = $this->organizations->managerUserIds((int) $entitlement->organization_id);

            foreach ($recipients as $userId) {
                $this->notifications->companyPurchaseExpiring(
                    recipientUserId: $userId,
                    entitlementRef: (string) $entitlement->public_id,
                    productTitle: $this->titleOf($entitlement),
                    expiresAt: $entitlement->access_ends_at->toIso8601String(),
                    daysBefore: $offset,
                    seatsAffected: (int) $entitlement->seats_used,
                );
                $sent++;
            }
        }

        return $sent;
    }

    /** The same window, told to the employees who are actually working through the courses. */
    private function sweepSeatAccess(Carbon $now): int
    {
        $sent = 0;

        foreach ($this->expiringEntitlements($now) as $entitlement) {
            // A purchase whose policy lets employee access outlive it has nothing to warn about.
            if (! $entitlement->employee_access_expires_with_purchase) {
                continue;
            }

            $offset = $this->dueOffset($entitlement->access_ends_at, $this->offsetsFor($entitlement), $now);

            if ($offset === null) {
                continue;
            }

            $holders = CompanyEntitlementAssignment::query()
                ->where('company_entitlement_id', $entitlement->id)
                ->whereNull('revoked_at')
                ->pluck('user_id');

            foreach ($holders as $userId) {
                $this->notifications->seatAccessExpiring(
                    recipientUserId: (int) $userId,
                    entitlementRef: (string) $entitlement->public_id,
                    productTitle: $this->titleOf($entitlement),
                    expiresAt: $entitlement->access_ends_at->toIso8601String(),
                    daysBefore: $offset,
                );
                $sent++;
            }
        }

        return $sent;
    }

    /** Credentials approaching the end of their validity → the learner who earned them. */
    private function sweepCertificates(Carbon $now): int
    {
        $sent = 0;
        $horizon = max(self::DEFAULT_OFFSETS);

        foreach ($this->certificates->expiringWithin($horizon) as $certificate) {
            $offsets = $this->offsetsForCourse($certificate->courseId);
            $expiresAt = Carbon::parse($certificate->expiresAt);
            $offset = $this->dueOffset($expiresAt, $offsets, $now);

            if ($offset === null) {
                continue;
            }

            $this->notifications->certificateExpiring(
                recipientUserId: $certificate->userId,
                certificateRef: $certificate->publicId,
                certificateNumber: $certificate->number,
                courseTitle: $certificate->courseTitle,
                expiresAt: $expiresAt->toIso8601String(),
                daysBefore: $offset,
            );
            $sent++;
        }

        return $sent;
    }

    /**
     * Active entitlements with an access window still ahead of them but inside the widest lead time
     * anyone could have configured.
     *
     * @return Collection<int, CompanyEntitlement>
     */
    private function expiringEntitlements(Carbon $now)
    {
        $horizon = max(self::DEFAULT_OFFSETS);

        return CompanyEntitlement::query()
            ->where('status', CompanyEntitlementStatus::Active->value)
            ->whereNotNull('access_ends_at')
            ->whereBetween('access_ends_at', [$now, $now->copy()->addDays($horizon)])
            ->with('product')
            ->get();
    }

    /**
     * The largest configured offset that the remaining time has dropped to or below — the notice
     * that is due today.
     *
     * Using "at or below" rather than "exactly equal" is what makes a sweep that misses a day (a
     * failed run, a paused scheduler) still send the notice rather than skipping it silently. The
     * dedup key stops the same offset being sent again on subsequent days.
     *
     * @param  list<int>  $offsets
     */
    private function dueOffset(?Carbon $expiresAt, array $offsets, Carbon $now): ?int
    {
        if ($expiresAt === null || $offsets === []) {
            return null;
        }

        // Computed from timestamps rather than a Carbon diff helper so the sign is unambiguous:
        // a window that closed yesterday must come out negative, not as a positive distance.
        $daysLeft = (int) ceil(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);

        if ($daysLeft < 0) {
            return null; // already gone; a reminder would be an obituary
        }

        $due = array_values(array_filter($offsets, static fn (int $o): bool => $daysLeft <= $o));

        return $due === [] ? null : min($due);
    }

    /**
     * @return list<int>
     */
    private function offsetsFor(CompanyEntitlement $entitlement): array
    {
        $product = $entitlement->getRelationValue('product');

        return $product instanceof Product
            ? $this->normalizeOffsets($product->reminderOffsets())
            : self::DEFAULT_OFFSETS;
    }

    /**
     * @return list<int>
     */
    private function offsetsForCourse(int $courseId): array
    {
        $product = Product::query()
            ->active()
            ->whereHas('courses', fn ($q) => $q->whereKey($courseId))
            ->orderByDesc('id')
            ->first();

        return $product instanceof Product
            ? $this->normalizeOffsets($product->reminderOffsets())
            : self::DEFAULT_OFFSETS;
    }

    /**
     * @param  list<int>  $offsets
     * @return list<int>
     */
    private function normalizeOffsets(array $offsets): array
    {
        return $offsets === [] ? self::DEFAULT_OFFSETS : $offsets;
    }

    private function titleOf(CompanyEntitlement $entitlement): string
    {
        $product = $entitlement->getRelationValue('product');

        return $product instanceof Product ? (string) $product->title : '';
    }
}
