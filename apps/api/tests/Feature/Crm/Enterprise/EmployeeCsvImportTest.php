<?php

declare(strict_types=1);

use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Services\EmployeeCsvImporter;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A CSV exercising every path: valid rows, an in-file duplicate, an invalid email, a formula-injection
 * email (neutralized -> invalid), a formula-injection NAME (neutralized -> kept), and a malformed
 * (short) row.
 */
function sampleCsv(): string
{
    return implode("\n", [
        'email,name,role',
        'alice@corp.com,Alice,member',
        'bob@corp.com,Bob,admin',
        'alice@corp.com,AliceDup,member',
        'bad-email,Nope,member',
        '=2+3,Formula,member',
        'dave@corp.com,=SUM(A1),member',
        'charlie@corp.com,Charlie',
    ]);
}

it('dry-run reports valid, duplicate, invalid, injected and malformed rows without writing', function (): void {
    $org = Organization::factory()->create();

    $report = app(EmployeeCsvImporter::class)->analyze($org, sampleCsv());

    expect($report['summary'])->toBe(['total' => 7, 'valid' => 3, 'errors' => 3, 'duplicates' => 1])
        ->and(OrganizationMember::where('organization_id', $org->id)->count())->toBe(0); // nothing written

    // The injected NAME is neutralized (prefixed with a single quote) so a spreadsheet cannot execute it.
    $dave = collect($report['rows'])->firstWhere('email', 'dave@corp.com');
    expect($dave['name'])->toBe("'=SUM(A1)")
        ->and($dave['status'])->toBe('valid');

    // The injected EMAIL is neutralized and therefore fails email validation -> surfaced as an error.
    $formula = collect($report['rows'])->firstWhere('status', 'error');
    expect(collect($report['rows'])->where('status', 'error')->count())->toBe(3);
});

it('commit creates members for the valid, non-duplicate rows only', function (): void {
    $org = Organization::factory()->create();

    $result = app(EmployeeCsvImporter::class)->commit($org, sampleCsv(), invite: false);

    expect($result['created'])->toBe(3)
        ->and(OrganizationMember::where('organization_id', $org->id)->count())->toBe(3)
        ->and(OrganizationMember::where('organization_id', $org->id)->where('email', 'alice@corp.com')->count())->toBe(1);
});

it('flags an existing member as a duplicate against the org', function (): void {
    $org = Organization::factory()->create();
    OrganizationMember::create(['organization_id' => $org->id, 'email' => 'bob@corp.com', 'role' => 'member', 'status' => 'active']);

    $report = app(EmployeeCsvImporter::class)->analyze($org, sampleCsv());

    $bob = collect($report['rows'])->firstWhere('email', 'bob@corp.com');
    expect($bob['status'])->toBe('duplicate');
});

it('rejects an oversized upload', function (): void {
    config(['crm.import.max_bytes' => 10]);
    $org = Organization::factory()->create();

    expect(fn () => app(EmployeeCsvImporter::class)->analyze($org, sampleCsv()))
        ->toThrow(\App\Domains\Crm\Exceptions\EmployeeImportException::class);
});
