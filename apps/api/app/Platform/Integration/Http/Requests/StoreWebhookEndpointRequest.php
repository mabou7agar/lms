<?php

declare(strict_types=1);

namespace App\Platform\Integration\Http\Requests;

use App\Platform\Integration\Emission\WebhookEventCatalog;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates creation of a customer webhook endpoint. event_types must be non-empty and every entry
 * must be a KNOWN published event name (the catalog is the whitelist). The URL's SSRF/transport
 * policy is enforced separately by WebhookUrlGuard in the controller (needs DNS resolution).
 */
class StoreWebhookEndpointRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'url' => ['required', 'string', 'url', 'max:2048'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(app(WebhookEventCatalog::class)->eventNames())],
        ];
    }
}
