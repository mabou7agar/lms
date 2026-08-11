<?php

declare(strict_types=1);

use App\Domains\Crm\Import\MemberImportPipeline;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Exercises every path of the reusable pipeline: valid rows, an in-file duplicate, an invalid email, a
 * formula-injection email (neutralized -> invalid -> surfaced), and a malformed (short) row.
 */
function importCsv(): string
{
    return implode("\n", [
        'email,name,role',
        'alice@corp.com,Alice,member',
        'bob@corp.com,Bob,admin',
        'alice@corp.com,AliceDup,member', // in-file duplicate
        'bad-email,Nope,member',          // invalid email
        '=2+3,Formula,member',            // formula-injection email -> neutralized -> invalid
        'charlie@corp.com,Charlie',       // malformed: fewer columns than header
    ]);
}

it('dry-run reports valid/duplicate/invalid/malformed rows and writes nothing', function (): void {
    $org = Organization::factory()->create();

    $report = app(MemberImportPipeline::class)->analyze($org, importCsv());

    expect($report['summary'])->toBe(['total' => 6, 'valid' => 2, 'errors' => 3, 'duplicates' => 1])
        ->and(OrganizationMember::where('organization_id', $org->id)->count())->toBe(0);

    // The malformed short row is surfaced as an explicit error, never silently dropped.
    $malformed = collect($report['rows'])->firstWhere('line', 7);
    expect($malformed['status'])->toBe('error')
        ->and($malformed['errors'][0])->toContain('Malformed row');

    // The formula-injection email was neutralized at parse time and therefore fails validation.
    expect(collect($report['rows'])->where('status', 'error')->count())->toBe(3);
});

it('commit creates only valid, non-duplicate rows and is idempotent', function (): void {
    $org = Organization::factory()->create();

    $first = app(MemberImportPipeline::class)->commit($org, importCsv());
    expect($first['created'])->toBe(2)
        ->and(OrganizationMember::where('organization_id', $org->id)->count())->toBe(2);

    // Re-running the same file creates nothing new (idempotent upsert on organization_id + email).
    $second = app(MemberImportPipeline::class)->commit($org, importCsv());
    expect($second['created'])->toBe(0)
        ->and(OrganizationMember::where('organization_id', $org->id)->count())->toBe(2);
});

it('confines dedup + writes to the caller organization (tenant isolation)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // The same email already exists in org B — it must NOT count as a duplicate for org A, and org B
    // must be untouched by an org A import.
    OrganizationMember::create(['organization_id' => $orgB->id, 'email' => 'bob@corp.com', 'role' => 'member', 'status' => 'active']);

    $result = app(MemberImportPipeline::class)->commit($orgA, "email,role\nbob@corp.com,member\n");

    expect($result['created'])->toBe(1)
        ->and(OrganizationMember::where('organization_id', $orgA->id)->where('email', 'bob@corp.com')->count())->toBe(1)
        ->and(OrganizationMember::where('organization_id', $orgB->id)->count())->toBe(1); // org B unchanged
});

it('flags an existing org member as a duplicate against the same org', function (): void {
    $org = Organization::factory()->create();
    OrganizationMember::create(['organization_id' => $org->id, 'email' => 'bob@corp.com', 'role' => 'member', 'status' => 'active']);

    $report = app(MemberImportPipeline::class)->analyze($org, importCsv());

    $bob = collect($report['rows'])->firstWhere('data.email', 'bob@corp.com');
    expect($bob['status'])->toBe('duplicate');
});

it('rejects an oversized upload before parsing', function (): void {
    config(['crm.import.max_bytes' => 10]);
    $org = Organization::factory()->create();

    expect(fn () => app(MemberImportPipeline::class)->analyze($org, importCsv()))
        ->toThrow(\App\Domains\Crm\Exceptions\CsvImportException::class);
});
