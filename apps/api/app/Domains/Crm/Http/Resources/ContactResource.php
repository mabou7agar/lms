<?php

namespace App\Domains\Crm\Http\Resources;

use App\Domains\Crm\Models\Contact;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property Contact $resource
 */
class ContactResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'first_name' => $this->resource->first_name,
            'last_name' => $this->resource->last_name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'title' => $this->resource->title,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
