<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
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
