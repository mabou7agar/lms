<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Contexts\Commerce\Models\Order;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property Order $resource
 */
class OrderResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'status' => $this->resource->status->value,
            'currency' => $this->resource->currency,
            'subtotal_minor' => $this->resource->subtotal_minor,
            'discount_minor' => $this->resource->discount_minor,
            'total_minor' => $this->resource->total_minor,
            // Buyer ownership + the billing identity this order was invoiced to.
            'buyer_type' => $this->resource->buyer_type?->value,
            'company_name' => $this->resource->company_name,
            'billing' => [
                'name' => $this->resource->billing_name,
                'email' => $this->resource->billing_email,
                'country' => $this->resource->billing_country,
                'tax_id' => $this->resource->billing_tax_id,
            ],
            'placed_at' => $this->resource->placed_at?->toIso8601String(),
            'paid_at' => $this->resource->paid_at?->toIso8601String(),
            'fulfilled_at' => $this->resource->fulfilled_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->resource->items->map(fn ($i) => [
                'title' => $i->title,
                'unit_amount_minor' => $i->unit_amount_minor,
            ])->values()),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->resource->invoice ? [
                'number' => $this->resource->invoice->number,
                'status' => $this->resource->invoice->status->value,
            ] : null),
        ];
    }
}
