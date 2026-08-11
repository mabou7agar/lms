<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * Upload for the reusable member-import pipeline. A framework-level size guard mirrors the pipeline's
 * own byte limit so an oversized body is rejected before it is ever read.
 */
class MemberImportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $maxKilobytes = (int) ceil(((int) config('crm.import.max_bytes', 2 * 1024 * 1024)) / 1024);

        return [
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv', 'max:'.$maxKilobytes],
            // false / absent => DRY-RUN validation report; true => commit the valid rows.
            'commit' => ['sometimes', 'boolean'],
        ];
    }
}
