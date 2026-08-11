<?php

namespace App\Domains\Crm\Import;

use App\Domains\Crm\Models\Organization;
use App\Platform\Shared\Services\BaseService;

/**
 * Reusable, tenant-scoped CSV import pipeline. Concrete importers (e.g. {@see MemberImportPipeline})
 * declare their columns, validation, dedup key and persistence; this base owns the SAFETY MODEL that
 * every import must share, mirroring the CRM EmployeeCsvImporter:
 *
 *   analyze()  — a DRY RUN. Parses + validates every row and returns a per-row report
 *                (valid / error / duplicate) WITHOUT writing anything. A malformed or invalid row is
 *                surfaced as an explicit error, never silently dropped.
 *   commit()   — persists the valid, non-duplicate rows inside one transaction. Idempotent: the
 *                concrete persist() upserts on the dedup key, so re-running a commit never doubles rows.
 *
 * SECURITY: size + row-count limits reject oversized uploads before parsing; every cell is neutralized
 * against spreadsheet formula injection at parse time; dedup is batched (existing keys resolved with a
 * single lookup, not one query per row); and the caller passes the tenant Organization — the pipeline
 * confines every existing-key lookup and every write to it, so an import can never touch another org.
 */
abstract class CsvImportPipeline extends BaseService
{
    /**
     * DRY RUN validation report — writes nothing.
     *
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function analyze(Organization $organization, string $csv): array
    {
        $rows = $this->validateRows($organization, $csv);

        return ['summary' => $this->summarize($rows), 'rows' => $rows];
    }

    /**
     * Persist the valid, non-duplicate rows. Idempotent per organization + dedup key.
     *
     * @return array{summary: array<string, int>, created: int, skipped: int, errors: list<array<string, mixed>>}
     */
    public function commit(Organization $organization, string $csv): array
    {
        $rows = $this->validateRows($organization, $csv);

        $created = 0;
        $skipped = 0;
        $errors = [];

        $this->transaction(function () use ($organization, $rows, &$created, &$skipped, &$errors): void {
            foreach ($rows as $row) {
                if ($row['status'] !== 'valid') {
                    if ($row['status'] === 'error') {
                        $errors[] = ['line' => $row['line'], 'errors' => $row['errors']];
                    }
                    $skipped++;

                    continue;
                }

                /** @var array<string, mixed> $data */
                $data = $row['data'];

                if ($this->persist($organization, $data)) {
                    $created++;
                } else {
                    $skipped++;
                }
            }
        });

        return [
            'summary' => $this->summarize($rows),
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Parse, validate and tag every row with a status (valid / error / duplicate) plus its normalized
     * data. Duplicate detection covers BOTH within-file repeats and existing rows in the organization.
     *
     * @return list<array<string, mixed>>
     */
    private function validateRows(Organization $organization, string $csv): array
    {
        $parsed = $this->parser()->parse($csv, $this->requiredColumns());

        $existingKeys = $this->existingKeys($organization);
        $seen = [];
        $result = [];

        foreach ($parsed as $entry) {
            if ($entry['malformed']) {
                $result[] = [
                    'line' => $entry['line'],
                    'status' => 'error',
                    'data' => null,
                    'errors' => ['Malformed row: column count does not match the header.'],
                ];

                continue;
            }

            $outcome = $this->validateRow($organization, $entry['cells']);
            $errors = $outcome['errors'];
            $data = $outcome['data'];

            $status = 'valid';
            $key = $errors === [] ? $this->dedupKey($data) : null;

            if ($errors !== []) {
                $status = 'error';
            } elseif ($key !== null && (isset($seen[$key]) || isset($existingKeys[$key]))) {
                $status = 'duplicate';
            }

            if ($key !== null) {
                $seen[$key] = true;
            }

            $result[] = [
                'line' => $entry['line'],
                'status' => $status,
                'data' => $data,
                'errors' => $errors,
            ];
        }

        return $result;
    }

    private function parser(): CsvImportParser
    {
        return new CsvImportParser(
            maxBytes: (int) config('crm.import.max_bytes', 2 * 1024 * 1024),
            maxRows: (int) config('crm.import.max_rows', 5000),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summarize(array $rows): array
    {
        $counts = ['total' => count($rows), 'valid' => 0, 'errors' => 0, 'duplicates' => 0];

        foreach ($rows as $row) {
            match ($row['status']) {
                'valid' => $counts['valid']++,
                'duplicate' => $counts['duplicates']++,
                default => $counts['errors']++,
            };
        }

        return $counts;
    }

    /**
     * Columns that MUST be present in the header (lower-cased).
     *
     * @return list<string>
     */
    abstract protected function requiredColumns(): array;

    /**
     * Batched lookup of the dedup keys already present in the organization — one query, keyed for O(1)
     * membership tests (value => true).
     *
     * @return array<string, true>
     */
    abstract protected function existingKeys(Organization $organization): array;

    /**
     * Validate + normalize a single parsed row.
     *
     * @param  array<string, string>  $cells
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    abstract protected function validateRow(Organization $organization, array $cells): array;

    /**
     * The dedup key for a validated row (e.g. its email), or null when the row cannot be deduped.
     *
     * @param  array<string, mixed>  $data
     */
    abstract protected function dedupKey(array $data): ?string;

    /**
     * Persist one validated row. MUST be idempotent (upsert on the dedup key). Returns true when a new
     * row was created, false when it already existed (skipped) — so re-running a commit is a no-op.
     *
     * @param  array<string, mixed>  $data
     */
    abstract protected function persist(Organization $organization, array $data): bool;
}
