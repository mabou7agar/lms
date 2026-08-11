<?php

namespace App\Domains\Crm\Import;

use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Contracts\UserLookupPort;

/**
 * Reference concrete importer built on {@see CsvImportPipeline}: bulk organization-member import.
 *
 * Self-contained — it writes only CRM's OWN OrganizationMember rows (no cross-context write), links an
 * existing account through the Identity UserLookupPort, and is idempotent: a member is upserted on
 * (organization_id, email), so re-committing the same file never creates a duplicate. Required column
 * is `email`; optional `role` and `department` columns are validated against the org. A formula-
 * injection email is neutralized at parse time and then fails email validation, so it surfaces as an
 * explicit per-row error rather than being written or silently skipped.
 */
class MemberImportPipeline extends CsvImportPipeline
{
    public function __construct(private readonly UserLookupPort $users) {}

    /** Memoized per-org department name => id map so department resolution is one query, not per row. */
    private ?int $departmentOrgId = null;

    /** @var array<string, int>|null */
    private ?array $departmentMap = null;

    protected function requiredColumns(): array
    {
        return ['email'];
    }

    protected function existingKeys(Organization $organization): array
    {
        return OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->pluck('email')
            ->mapWithKeys(static fn ($e): array => [mb_strtolower((string) $e) => true])
            ->all();
    }

    protected function validateRow(Organization $organization, array $cells): array
    {
        $errors = [];

        $email = mb_strtolower(trim($cells['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Invalid or missing email.';
        }

        $role = trim($cells['role'] ?? '') ?: MemberRole::Member->value;
        if (! in_array($role, MemberRole::values(), true)) {
            $errors[] = 'Invalid role.';
            $role = MemberRole::Member->value;
        }

        $departmentId = null;
        $departmentName = trim($cells['department'] ?? '');
        if ($departmentName !== '') {
            $departmentId = $this->departments($organization)[mb_strtolower($departmentName)] ?? null;
            if ($departmentId === null) {
                $errors[] = 'Unknown department.';
            }
        }

        return [
            'data' => ['email' => $email, 'role' => $role, 'department_id' => $departmentId],
            'errors' => $errors,
        ];
    }

    protected function dedupKey(array $data): ?string
    {
        $email = (string) ($data['email'] ?? '');

        return $email === '' ? null : $email;
    }

    protected function persist(Organization $organization, array $data): bool
    {
        $email = (string) $data['email'];

        // Idempotent upsert on (organization_id, email): a re-run finds the existing member and skips.
        $existing = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('email', $email)
            ->exists();

        if ($existing) {
            return false;
        }

        OrganizationMember::create([
            'organization_id' => $organization->id,
            'department_id' => $data['department_id'],
            'user_id' => $this->users->idByEmail($email),
            'email' => $email,
            'role' => $data['role'],
            'status' => MemberStatus::Active->value,
            'joined_at' => now(),
        ]);

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function departments(Organization $organization): array
    {
        if ($this->departmentMap !== null && $this->departmentOrgId === (int) $organization->id) {
            return $this->departmentMap;
        }

        $this->departmentOrgId = (int) $organization->id;
        $this->departmentMap = Department::query()
            ->where('organization_id', $organization->id)
            ->get(['id', 'name'])
            ->mapWithKeys(static fn ($d): array => [mb_strtolower((string) $d->name) => (int) $d->id])
            ->all();

        return $this->departmentMap;
    }
}
