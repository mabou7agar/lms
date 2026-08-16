<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * P1: `image` resolves the stored `image_path` (a MediaAsset public_id reference or a legacy URL) to
 * a public URL — a PUBLIC asset yields a stable URL, a legacy value passes through, a private/missing
 * asset yields null (never a raw reference or storage key).
 *
 * @property Product $resource
 */
class ProductResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'type' => $this->resource->type->value,
            'title' => $this->resource->localized('title'),
            'slug' => $this->resource->slug,
            'description' => $this->resource->localized('description'),
            'image' => app(PublicAssetUrlResolver::class)->resolve($this->resource->image_path),
            'prices' => $this->whenLoaded('prices', fn () => $this->resource->prices->map(fn ($p) => [
                'currency' => $p->currency,
                'amount_minor' => $p->amount_minor,
                'sale_amount_minor' => $p->sale_amount_minor,
                'on_sale' => $p->onSale(),
                'effective_minor' => $p->effectiveMinor(),
            ])->values()),
            // Commercial policy. Additive — existing keys above are untouched, so current consumers
            // keep working. Courses are exposed by public id only.
            // Null-safe throughout: the columns carry database defaults, so a model that has not
            // been persisted (or predates the policy migration) has no value in memory and must not
            // fatal a response.
            'audience' => $this->resource->audience?->value,
            // Courses are read through getAttribute so this Commerce resource never depends on the
            // Catalog model's property shape (the models stay in separate contexts).
            'courses' => $this->whenLoaded('courses', fn () => $this->resource->courses->map(fn ($c): array => [
                'id' => $c->getAttribute('public_id'),
                'title' => $c->localized('title'),
                'slug' => $c->getAttribute('slug'),
            ])->values()),
            'access' => [
                'duration_type' => $this->resource->access_duration_type?->value,
                'duration_value' => $this->resource->access_duration_value,
                'ends_at' => $this->resource->access_ends_at?->toIso8601String(),
            ],
            'certificate' => [
                'enabled' => $this->resource->certificate_enabled,
                'expiry_type' => $this->resource->certificate_expiry_type?->value,
                'expiry_value' => $this->resource->certificate_expiry_value,
            ],
            // Only meaningful to a company buyer; harmless for an individual to read.
            'seats' => [
                'mode' => $this->resource->seat_mode?->value,
                'default_count' => $this->resource->default_seat_count,
                'reassignment_policy' => $this->resource->seat_reassignment_policy?->value,
                'reassignment_progress_threshold' => $this->resource->reassignment_progress_threshold,
                'employee_access_expires_with_purchase' => $this->resource->employee_access_expires_with_purchase,
            ],
        ];
    }
}
