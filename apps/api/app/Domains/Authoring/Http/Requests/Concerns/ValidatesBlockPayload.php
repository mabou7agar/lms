<?php

namespace App\Domains\Authoring\Http\Requests\Concerns;

use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Support\BlockPayloadRules;
use App\Platform\Shared\Helpers\LocaleHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFactory;

/**
 * C5 - Shared per-type, per-locale validation for a block's localized `content_i18n` map. Applied
 * from a request's after-hook so the base `rules()` stay declarative.
 *
 * Guarantees the payload is a CONTROLLED shape, not an arbitrary blob:
 *  - each key of content_i18n must be a supported authoring locale (en/ar),
 *  - each locale's value must be an object,
 *  - each object is validated against BlockPayloadRules::forType() for the block's type,
 *  - any key not in that type's schema is rejected (no smuggled fields).
 */
trait ValidatesBlockPayload
{
    protected function validateLocalizedPayload(Validator $validator): void
    {
        $type = $this->resolveBlockType();

        // A null type means either it was omitted (update with no type change but also no content) or
        // it failed the base Rule::in check — in both cases the base rules already reported it.
        if ($type === null) {
            return;
        }

        $map = $this->input('content_i18n');
        if (! is_array($map)) {
            return;
        }

        $rules = BlockPayloadRules::forType($type);
        $allowed = array_keys($rules);
        $supportedLocales = LocaleHelper::supported();

        foreach ($map as $locale => $payload) {
            if (! in_array((string) $locale, $supportedLocales, true)) {
                $validator->errors()->add("content_i18n.{$locale}", 'Unsupported authoring locale.');

                continue;
            }

            if (! is_array($payload)) {
                $validator->errors()->add("content_i18n.{$locale}", 'Block content must be an object.');

                continue;
            }

            foreach (array_diff(array_keys($payload), $allowed) as $unknown) {
                $validator->errors()->add(
                    "content_i18n.{$locale}.{$unknown}",
                    'Unsupported field for this block type.'
                );
            }

            $sub = ValidatorFactory::make($payload, $rules);
            foreach ($sub->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("content_i18n.{$locale}.{$field}", $message);
                }
            }
        }
    }

    /**
     * Resolve the block's type for payload validation: from the request body when present (create,
     * or an update that changes the type), otherwise from the route-bound block (update that keeps
     * its type but edits content).
     */
    protected function resolveBlockType(): ?BlockType
    {
        $value = $this->input('type');
        if (is_string($value)) {
            return BlockType::tryFrom($value);
        }

        $block = $this->route('block');

        return $block instanceof Block ? $block->type : null;
    }
}
