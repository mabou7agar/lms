<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Platform\Shared\Requests\BaseFormRequest;

class EmployeeImportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $maxKilobytes = (int) ceil(((int) config('crm.import.max_bytes', 2 * 1024 * 1024)) / 1024);

        return [
            // A CSV file; framework-level size guard mirrors the importer's byte limit.
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv', 'max:'.$maxKilobytes],
            // When true, commit the import; otherwise return a dry-run validation report.
            'commit' => ['sometimes', 'boolean'],
            // When committing, whether to send invitations instead of directly activating members.
            'invite' => ['sometimes', 'boolean'],
        ];
    }
}
