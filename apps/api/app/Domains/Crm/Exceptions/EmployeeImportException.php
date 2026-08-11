<?php

namespace App\Domains\Crm\Exceptions;

class EmployeeImportException extends CrmException
{
    protected string $errorCode = 'CRM_IMPORT_INVALID';

    protected int $status = 422;

    public static function tooLarge(int $maxBytes): self
    {
        return new self('The uploaded file is too large.', ['max_bytes' => $maxBytes]);
    }

    public static function tooManyRows(int $maxRows): self
    {
        return new self('The file has too many rows.', ['max_rows' => $maxRows]);
    }

    public static function missingEmailColumn(): self
    {
        return new self('The CSV must have an "email" column.');
    }

    public static function empty(): self
    {
        return new self('The CSV has no data rows.');
    }
}
