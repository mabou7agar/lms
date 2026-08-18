<?php

namespace App\Contexts\Commerce\Adapters;

use App\Contexts\Commerce\Enums\ProductType;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Commerce\Contracts\PurchaseSummaryPort;
use App\Platform\Shared\Commerce\Data\PurchaseSummary;
use Illuminate\Database\Eloquent\Builder;

/**
 * Commerce's implementation of the Shared PurchaseSummaryPort.
 *
 * A course can be granted by more than one active product — typically its own single-course product
 * plus one or more bundles. The headline offer is the single-course product when one exists (that is
 * the price a course page should quote); bundles that also include it are reported separately so the
 * page can cross-sell them.
 */
class PurchaseSummaryAdapter implements PurchaseSummaryPort
{
    public function forCourse(int $courseId): PurchaseSummary
    {
        return $this->forCourseIds([$courseId])[$courseId] ?? PurchaseSummary::notPurchasable();
    }

    /**
     * @param  list<int>  $courseIds
     * @return array<int, PurchaseSummary>
     */
    public function forCourseIds(array $courseIds): array
    {
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));

        // Every id answers, even when nothing sells it.
        $summaries = array_fill_keys($courseIds, PurchaseSummary::notPurchasable());

        if ($courseIds === []) {
            return $summaries;
        }

        $products = Product::query()
            ->active()
            ->with(['prices'])
            ->whereHas('courses', fn (Builder $q): Builder => $q->whereIn('courses.id', $courseIds))
            ->with(['courses' => fn ($q) => $q->select('courses.id')])
            ->get();

        foreach ($courseIds as $courseId) {
            // Course ids only — the Catalog model itself never crosses into Commerce.
            $granting = $products->filter(
                fn (Product $p): bool => in_array($courseId, $p->courseIds(), true),
            );

            if ($granting->isEmpty()) {
                continue;
            }

            // Quote the single-course product when there is one; otherwise the bundle.
            //
            // Sorted by id first, because "which product sells this course" must not depend on the
            // order the database happened to return rows in. A course can end up with more than one
            // active course-product (an import, a duplicate, a QA fixture), and when it does an
            // unordered pick means the catalogue card, the detail page and the cart can each quote a
            // different price for the same course. Oldest wins: it is the one already advertised.
            $ordered = $granting->sortBy('id');
            $primary = $ordered->firstWhere('type', ProductType::Course) ?? $ordered->first();
            $bundleIds = $granting
                ->filter(fn (Product $p): bool => $p->type === ProductType::Bundle && $p->id !== $primary->id)
                ->map(fn (Product $p): string => (string) $p->public_id)
                ->values()
                ->all();

            $price = $primary->defaultPrice();

            $summaries[$courseId] = new PurchaseSummary(
                purchasable: true,
                productId: (string) $primary->public_id,
                productType: $primary->type->value,
                currency: $price?->currency,
                amountMinor: $price?->amount_minor,
                effectiveMinor: $price?->effectiveMinor(),
                onSale: (bool) $price?->onSale(),
                audience: $primary->audience?->value,
                accessDurationType: $primary->access_duration_type?->value,
                accessDurationValue: $primary->access_duration_value,
                accessEndsAt: $primary->access_ends_at?->toIso8601String(),
                certificateEnabled: (bool) $primary->certificate_enabled,
                certificateExpiryType: $primary->certificate_expiry_type?->value,
                certificateExpiryValue: $primary->certificate_expiry_value,
                includedInBundleIds: $bundleIds,
            );
        }

        return $summaries;
    }
}
