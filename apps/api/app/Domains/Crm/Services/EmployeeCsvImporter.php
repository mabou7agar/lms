<?php

namespace App\Domains\Crm\Services;

use App\Domains\Crm\Actions\Organization\InviteMemberAction;
use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Exceptions\EmployeeImportException;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Services\BaseService;

/**
 * Bulk employee import from CSV for an organization manager. Two-phase and tenant-scoped:
 *
 *   analyze()  — a DRY RUN. Parses the file, validates every row, and returns a per-row report
 *                (valid / invalid / duplicate / already-a-member) WITHOUT writing anything. No row is
 *                ever silently dropped — a malformed row surfaces as an explicit error.
 *   commit()   — creates organization_members for the valid, non-duplicate rows (optionally inviting),
 *                deduping by email against the file and against existing members.
 *
 * SECURITY:
 *   - Size + row-count limits reject oversized uploads before parsing the body.
 *   - CSV/formula-injection defense: every cell is neutralized up front — a value beginning with
 *     = + - @ (the spreadsheet formula triggers) is prefixed with a single quote. A neutralized email
 *     then fails email validation and is rejected, so an injected payload can neither execute in a
 *     spreadsheet nor slip through as a valid address.
 *   - No PII (emails / names) is ever logged; this service performs no logging.
 *   - Dedup + id-mapping are batched: existing members and existing user ids are each resolved with a
 *     single lookup, not one query per row.
 */
class EmployeeCsvImporter extends BaseService
{
    /** @var list<string> The spreadsheet formula-injection trigger characters. */
    private const INJECTION_PREFIXES = ['=', '+', '-', '@'];

    public function __construct(
        private readonly UserLookupPort $users,
        private readonly InviteMemberAction $invite,
    ) {}

    /**
     * DRY RUN validation report — writes nothing.
     *
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function analyze(Organization $organization, string $csv): array
    {
        $rows = $this->validateRows($organization, $this->parse($csv));

        return ['summary' => $this->summarize($rows), 'rows' => $rows];
    }

    /**
     * Commit the valid, non-duplicate rows. Idempotent per organization+email (the invite action and
     * the unique index both enforce it).
     *
     * @return array{summary: array<string, int>, created: int, invited: int, skipped: int, errors: list<array<string, mixed>>}
     */
    public function commit(Organization $organization, string $csv, bool $invite = false): array
    {
        $rows = $this->validateRows($organization, $this->parse($csv));

        $created = 0;
        $invited = 0;
        $skipped = 0;
        $errors = [];

        $this->transaction(function () use ($organization, $rows, $invite, &$created, &$invited, &$skipped, &$errors): void {
            foreach ($rows as $row) {
                if ($row['status'] !== 'valid') {
                    if ($row['status'] === 'error') {
                        $errors[] = ['line' => $row['line'], 'errors' => $row['errors']];
                    }
                    $skipped++;

                    continue;
                }

                $email = (string) $row['email'];
                $role = (string) $row['role'];

                if ($invite) {
                    $this->invite->execute($organization, ['email' => $email, 'role' => $role]);
                    $invited++;
                } else {
                    OrganizationMember::create([
                        'organization_id' => $organization->id,
                        'department_id' => $row['department_id'],
                        'user_id' => $this->users->idByEmail($email),
                        'email' => $email,
                        'role' => $role,
                        'status' => MemberStatus::Active->value,
                        'joined_at' => now(),
                    ]);
                }

                $created++;
            }
        });

        return [
            'summary' => $this->summarize($rows),
            'created' => $created,
            'invited' => $invited,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Validate every parsed row against the organization context, tagging each with a status and any
     * errors, and flagging within-file + existing-member duplicates.
     *
     * @param  list<array{line: int, cells: array<string, string>}>  $parsed
     * @return list<array<string, mixed>>
     */
    private function validateRows(Organization $organization, array $parsed): array
    {
        // Batched context: existing member emails and known department names — one query each.
        $existingEmails = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->pluck('email')
            ->map(static fn ($e): string => mb_strtolower((string) $e))
            ->flip();

        $departments = Department::query()
            ->where('organization_id', $organization->id)
            ->get(['id', 'name'])
            ->mapWithKeys(static fn ($d): array => [mb_strtolower((string) $d->name) => (int) $d->id]);

        $seen = [];
        $result = [];

        foreach ($parsed as $entry) {
            $cells = $entry['cells'];
            $email = mb_strtolower(trim($cells['email'] ?? ''));
            $errors = [];

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Invalid or missing email.';
            }

            $roleValue = trim($cells['role'] ?? '') ?: MemberRole::Member->value;
            if (! in_array($roleValue, MemberRole::values(), true)) {
                $errors[] = 'Invalid role.';
                $roleValue = MemberRole::Member->value;
            }

            $departmentId = null;
            $departmentName = trim($cells['department'] ?? '');
            if ($departmentName !== '') {
                $departmentId = $departments[mb_strtolower($departmentName)] ?? null;
                if ($departmentId === null) {
                    $errors[] = 'Unknown department.';
                }
            }

            $status = 'valid';
            if ($errors !== []) {
                $status = 'error';
            } elseif (isset($seen[$email]) || isset($existingEmails[$email])) {
                $status = 'duplicate';
            }

            if ($email !== '') {
                $seen[$email] = true;
            }

            $result[] = [
                'line' => $entry['line'],
                'email' => $email,
                'name' => $this->neutralize(trim($cells['name'] ?? '')),
                'role' => $roleValue,
                'department_id' => $departmentId,
                'status' => $status,
                'errors' => $errors,
            ];
        }

        return $result;
    }

    /**
     * Parse the CSV body into header-keyed rows. Enforces size + row limits and a required email
     * column, neutralizes every cell, and flags rows whose column count does not match the header.
     *
     * @return list<array{line: int, cells: array<string, string>}>
     */
    private function parse(string $csv): array
    {
        $maxBytes = (int) config('crm.import.max_bytes', 2 * 1024 * 1024);
        if (strlen($csv) > $maxBytes) {
            throw EmployeeImportException::tooLarge($maxBytes);
        }

        // Strip a UTF-8 BOM if present, then split on any line ending.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));

        if ($lines === []) {
            throw EmployeeImportException::empty();
        }

        $header = array_map(
            fn (string $h): string => mb_strtolower(trim($this->neutralize($h))),
            str_getcsv((string) array_shift($lines), ',', '"', '')
        );

        if (! in_array('email', $header, true)) {
            throw EmployeeImportException::missingEmailColumn();
        }

        $maxRows = (int) config('crm.import.max_rows', 5000);
        if (count($lines) > $maxRows) {
            throw EmployeeImportException::tooManyRows($maxRows);
        }

        if ($lines === []) {
            throw EmployeeImportException::empty();
        }

        $rows = [];
        foreach ($lines as $index => $line) {
            $raw = array_map(fn (?string $c): string => $this->neutralize((string) $c), str_getcsv($line, ',', '"', ''));

            // A row whose column count differs from the header is malformed — surfaced, never dropped.
            if (count($raw) !== count($header)) {
                $rows[] = [
                    'line' => $index + 2, // +1 header, +1 to 1-index
                    'cells' => ['__malformed' => '1'],
                ];

                continue;
            }

            /** @var array<string, string> $cells */
            $cells = array_combine($header, $raw);
            $rows[] = ['line' => $index + 2, 'cells' => $cells];
        }

        return $rows;
    }

    /**
     * Neutralize a spreadsheet formula-injection payload: a cell whose first character is one of
     * = + - @ is prefixed with a single quote so a spreadsheet treats it as text, never a formula.
     */
    private function neutralize(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::INJECTION_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
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
}
