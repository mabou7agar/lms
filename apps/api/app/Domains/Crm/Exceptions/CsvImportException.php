<?php

namespace App\Domains\Crm\Exceptions;

/**
 * Structural failure of a generic CSV import (oversized body, too many rows, empty file, or a missing
 * required column). These are whole-file rejections raised before per-row validation; individual bad
 * rows are NOT exceptions — they are surfaced as per-row errors so nothing is ever silently dropped.
 */
class CsvImportException extends CrmException
{
    protected string $errorCode = 'CRM_CSV_IMPORT_INVALID';

    protected int $status = 422;

    public static function tooLarge(int $maxBytes): self
    {
        return new self('The uploaded file is too large.', ['max_bytes' => $maxBytes]);
    }

    public static function tooManyRows(int $maxRows): self
    {
        return new self('The file has too many rows.', ['max_rows' => $maxRows]);
    }

    public static function empty(): self
    {
        return new self('The CSV has no data rows.');
    }

    /** @param list<string> $columns */
    public static function missingColumns(array $columns): self
    {
        return new self('The CSV is missing required column(s): '.implode(', ', $columns).'.', ['required' => $columns]);
    }
}
