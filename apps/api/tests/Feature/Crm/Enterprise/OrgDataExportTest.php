<?php

declare(strict_types=1);

use App\Domains\Crm\Jobs\ProcessOrgExportJob;
use App\Domains\Crm\Models\OrgDataExport;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Services\OrgExportService;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

/** An owner whose resolved tenant is $org. */
function exportOwner(Organization $org): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $user->id, 'email' => $user->email, 'role' => 'owner', 'status' => 'active']);

    return $user;
}

function bundleMember(Organization $org, string $email): void
{
    OrganizationMember::create(['organization_id' => $org->id, 'email' => $email, 'role' => 'member', 'status' => 'active']);
}

it('builds a bundle confined to the caller org, with a valid manifest, and never another org\'s row', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    bundleMember($orgA, 'alice@a.com');
    bundleMember($orgB, 'sentinel@b.com'); // org B's row must NEVER appear in org A's bundle

    $bundle = app(OrgExportService::class)->build($orgA);

    // Manifest describes the bundle.
    $manifest = $bundle['manifest'];
    expect($manifest)->toHaveKeys(['tenant', 'organization_id', 'dataset', 'generated_at', 'row_count', 'files'])
        ->and($manifest['tenant'])->toBe($orgA->public_id)
        ->and($manifest['organization_id'])->toBe($orgA->id)
        ->and($manifest['dataset'])->toBe('bi_bundle')
        ->and(collect($manifest['files'])->pluck('file')->all())
        ->toContain('members.csv', 'seat_usage.csv', 'enrollments_completions.csv', 'analytics_kpis.csv');

    // Serialize every dataset and assert isolation across the WHOLE bundle.
    $svc = app(OrgExportService::class);
    $allCsv = collect($bundle['files'])->map(fn (array $f): string => $svc->toCsv($f['columns'], $f['rows']))->implode("\n");

    expect($allCsv)->toContain('alice@a.com')
        ->and($allCsv)->not->toContain('sentinel@b.com');
});

it('queues an async export and produces an owner-downloadable, org-bound bundle', function (): void {
    Storage::fake('local');

    $orgA = Organization::factory()->create();
    bundleMember($orgA, 'alice@a.com');
    Sanctum::actingAs(exportOwner($orgA));

    $res = $this->postJson('/api/v1/enterprise/exports')
        ->assertCreated()->assertJsonPath('data.status', 'queued');

    $export = OrgDataExport::where('public_id', $res->json('data.id'))->firstOrFail();

    // Run the async job (afterCommit dispatch doesn't fire inside the test transaction).
    (new ProcessOrgExportJob($export->id))->handle(app(OrgExportService::class));

    $export->refresh();
    expect($export->status->value)->toBe('completed')
        ->and($export->row_count)->toBeGreaterThan(0)
        ->and(Storage::disk('local')->exists($export->storage_prefix.'/members.csv'))->toBeTrue()
        ->and(Storage::disk('local')->exists($export->storage_prefix.'/manifest.json'))->toBeTrue();

    // The stored prefix is never serialized.
    $show = $this->getJson("/api/v1/enterprise/exports/{$export->public_id}")->assertOk();
    expect($show->getContent())->not->toContain('storage_prefix');

    $downloads = $show->json('data.downloads');
    $membersUrl = $downloads['members.csv'];
    expect($membersUrl)->toContain('signature=')
        ->and($downloads)->toHaveKey('manifest.json');

    // The owner can fetch the signed file, and it contains only their row.
    $this->get($membersUrl)->assertOk()
        ->assertSee('alice@a.com');
});

it('404s an export belonging to another organization and rejects an unsigned file fetch', function (): void {
    Storage::fake('local');

    $orgA = Organization::factory()->create();
    bundleMember($orgA, 'alice@a.com');
    Sanctum::actingAs(exportOwner($orgA));

    $res = $this->postJson('/api/v1/enterprise/exports')->assertCreated();
    $export = OrgDataExport::where('public_id', $res->json('data.id'))->firstOrFail();
    (new ProcessOrgExportJob($export->id))->handle(app(OrgExportService::class));

    // Another org's owner cannot see org A's export -> 404 (confinement), so never receives a signed URL.
    $orgB = Organization::factory()->create();
    Sanctum::actingAs(exportOwner($orgB));
    $this->getJson("/api/v1/enterprise/exports/{$export->public_id}")->assertNotFound();

    // An unsigned (tampered/guessed) file URL is rejected by the signed middleware.
    $this->getJson("/api/v1/enterprise/exports/{$export->public_id}/file?file=members.csv&organization={$orgB->public_id}")
        ->assertForbidden();
});

it('denies a plain member the export capability', function (): void {
    $org = Organization::factory()->create();
    $plain = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $plain->id, 'email' => $plain->email, 'role' => 'member', 'status' => 'active']);
    Sanctum::actingAs($plain);

    $this->postJson('/api/v1/enterprise/exports')->assertForbidden();
    $this->getJson('/api/v1/enterprise/exports')->assertForbidden();
});

it('bounds the export build query count regardless of member volume (no per-row N+1)', function (): void {
    $org = Organization::factory()->create();
    foreach (range(1, 40) as $i) {
        bundleMember($org, "u{$i}@corp.com");
    }

    DB::enableQueryLog();
    app(OrgExportService::class)->build($org);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // A handful of bounded queries (roster join + port aggregates) — NOT one per member. With 40
    // members a per-row N+1 would blow well past this bound; the constant baseline stays under it.
    expect($count)->toBeLessThan(30);
});
