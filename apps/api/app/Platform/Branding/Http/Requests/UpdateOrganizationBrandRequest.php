<?php

namespace App\Platform\Branding\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * Validates an org-admin brand override. Every field is optional (a partial update) but strictly
 * shaped so no CSS/script can be injected:
 *   - brand names are plain text with NO angle brackets (a `<script>` is REJECTED, not just stripped),
 *   - colours must be a strict #RGB / #RRGGBB hex (an invalid hex or a `red`/`expression(...)` is
 *     rejected — arbitrary CSS colour functions can never reach the theme),
 *   - logo/favicon must be a bare media reference / relative path or an http(s) URL, so a
 *     `javascript:` (or any other scheme) value is rejected.
 * The service additionally strip_tags() the text fields as defence-in-depth before storage.
 */
class UpdateOrganizationBrandRequest extends BaseFormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $hex = 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';
        // A bare media public_id / relative path, OR an http(s) URL. Blocks javascript:, data:, etc.
        $asset = 'regex:#^(https?://[^\s]{1,2040}|[A-Za-z0-9._/\-]{1,2040})$#';
        $noMarkup = 'regex:/^[^<>]*$/';

        return [
            'brand_name_en' => ['sometimes', 'nullable', 'string', 'max:120', $noMarkup],
            'brand_name_ar' => ['sometimes', 'nullable', 'string', 'max:120', $noMarkup],
            'logo' => ['sometimes', 'nullable', 'string', 'max:2048', $asset],
            'favicon' => ['sometimes', 'nullable', 'string', 'max:2048', $asset],
            'primary_color' => ['sometimes', 'nullable', 'string', $hex],
            'secondary_color' => ['sometimes', 'nullable', 'string', $hex],
        ];
    }
}
