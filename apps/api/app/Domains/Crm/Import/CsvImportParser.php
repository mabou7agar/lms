<?php

namespace App\Domains\Crm\Import;

use App\Domains\Crm\Exceptions\CsvImportException;

/**
 * Generic, reusable CSV parser for the import framework. Enforces the size + row-count limits BEFORE
 * touching the body, requires a declared set of columns, neutralizes every cell against formula
 * injection, and — critically — surfaces a row whose column count does not match the header as an
 * explicit malformed row rather than dropping it. It never validates domain meaning; that is the
 * concrete pipeline's job.
 */
class CsvImportParser
{
    use CsvSafety;

    public function __construct(
        private readonly int $maxBytes,
        private readonly int $maxRows,
    ) {}

    /**
     * Parse the CSV body into header-keyed rows.
     *
     * @param  list<string>  $requiredColumns  columns that MUST appear in the header (lower-cased)
     * @return list<array{line: int, cells: array<string, string>, malformed: bool}>
     */
    public function parse(string $csv, array $requiredColumns): array
    {
        if (strlen($csv) > $this->maxBytes) {
            throw CsvImportException::tooLarge($this->maxBytes);
        }

        // Strip a UTF-8 BOM if present, then split on any line ending and drop blank lines.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));

        if ($lines === []) {
            throw CsvImportException::empty();
        }

        $header = array_map(
            fn (string $h): string => mb_strtolower(trim($this->neutralize($h))),
            str_getcsv((string) array_shift($lines), ',', '"', '')
        );

        $missing = array_values(array_diff($requiredColumns, $header));
        if ($missing !== []) {
            throw CsvImportException::missingColumns($missing);
        }

        if (count($lines) > $this->maxRows) {
            throw CsvImportException::tooManyRows($this->maxRows);
        }

        if ($lines === []) {
            throw CsvImportException::empty();
        }

        $rows = [];
        foreach ($lines as $index => $line) {
            $raw = array_map(fn (?string $c): string => $this->neutralize((string) $c), str_getcsv($line, ',', '"', ''));
            $lineNumber = $index + 2; // +1 header, +1 to 1-index

            // A row whose column count differs from the header is malformed — surfaced, never dropped.
            if (count($raw) !== count($header)) {
                $rows[] = ['line' => $lineNumber, 'cells' => [], 'malformed' => true];

                continue;
            }

            /** @var array<string, string> $cells */
            $cells = array_combine($header, $raw);
            $rows[] = ['line' => $lineNumber, 'cells' => $cells, 'malformed' => false];
        }

        return $rows;
    }
}
