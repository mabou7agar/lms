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
        ];
    }
}
