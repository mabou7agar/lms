<?php

declare(strict_types=1);

namespace App\Platform\Integration\Http\Requests;

use App\Platform\Integration\Emission\WebhookEventCatalog;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a partial update to an endpoint (name / description / url / subscribed event_types).
 * Every submitted event name is checked against the catalog whitelist.
 */
class UpdateWebhookEndpointRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'url' => ['sometimes', 'string', 'url', 'max:2048'],
            'event_types' => ['sometimes', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(app(WebhookEventCatalog::class)->eventNames())],
        ];
    }
}
