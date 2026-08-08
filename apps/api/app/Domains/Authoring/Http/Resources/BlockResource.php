<?php

namespace App\Domains\Authoring\Http\Resources;

use App\Domains\Authoring\Models\Block;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * C5 - A content block for authoring (admin) views. Never exposes internal ids or the legacy
 * `payload` mirror: `content` is the locale-resolved payload (via HasTranslations / the central
 * resolver, honouring an optional `?locale=` query), while `content_i18n` is the full bilingual map
 * the builder edits. `lock_version` is surfaced so the client can echo it back as `expected_version`.
 *
 * @property Block $resource
 */
class BlockResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        $locale = $request->query('locale');
        $locale = is_string($locale) ? $locale : null;

        return [
            'id' => $this->resource->public_id,
            'type' => $this->resource->type->value,
            'family' => $this->resource->family->value,
            'position' => (int) $this->resource->position,
            'publish_state' => $this->resource->publish_state->value,
            'lock_version' => (int) $this->resource->lock_version,
            'content' => $this->resource->translate('content_i18n', $locale),
            'content_i18n' => $this->resource->content_i18n,
            'config' => $this->resource->config,
            'learning_object_id' => $this->resource->learning_object_id,
        ];
    }
}
