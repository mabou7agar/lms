<?php

use App\Contexts\Analytics\Models\ExportJob;
use App\Domains\Certification\Database\Seeders\CertificationSeeder;
use App\Domains\Certification\Models\Certificate;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * M1 — a valid signature alone must not serve a file. Both stream routes now bind the owning user's
 * id into the signature and re-check it, so a leaked/replayed signed URL can never be repointed at
 * another user's export or certificate. These pin: owner ok, non-owner 403, tampered/expired sig
 * rejected, missing record 404, and the authorized (admin-minted) download still works end-to-end.
 */
beforeEach(function () {
    config(['certification.pdf.disk' => 'local', 'analytics.export.disk' => 'local']);
    Storage::fake('local');
});

// ---------------------------------------------------------------- helpers

function issuedCertificateWithPdf(User $owner): Certificate
{
    $cert = Certificate::factory()->create(['user_id' => $owner->id]);
    // Pre-store the PDF so the stream route's idempotent ensure() short-circuits (no renderer).
    $path = 'certificates/'.$cert->public_id.'.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 fake');
    $cert->forceFill(['pdf_path' => $path, 'pdf_generated_at' => now()])->save();

    return $cert;
}

function certFileUrl(Certificate $cert, int $owner, int $ttlMinutes = 15): string
{
    return URL::temporarySignedRoute('certificates.file', now()->addMinutes($ttlMinutes), [
        'certificate' => $cert->public_id,
        'owner' => $owner,
    ]);
}

function completedExport(User $owner): ExportJob
{
    $job = ExportJob::create([
        'user_id' => $owner->id,
        'format' => 'csv',
        'status' => 'completed',
        'source' => 'report',
        'file_path' => 'exports/e.csv',
        'row_count' => 1,
        'completed_at' => now(),
    ]);
    Storage::disk('local')->put('exports/e.csv', "a,b\n1,2\n");

    return $job;
}

function exportFileUrl(ExportJob $job, int $owner, int $ttlMinutes = 15): string
{
    return URL::temporarySignedRoute('analytics.exports.file', now()->addMinutes($ttlMinutes), [
        'export' => $job->public_id,
        'owner' => $owner,
    ]);
}

// ---------------------------------------------------------------- certificate file

it('serves a certificate to a valid signed URL bound to its owner', function () {
    $owner = User::factory()->create();
    $cert = issuedCertificateWithPdf($owner);

    $this->get(certFileUrl($cert, $owner->id))->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('refuses a validly-signed certificate URL bound to another user', function () {
    $owner = User::factory()->create();
    $cert = issuedCertificateWithPdf($owner);

    // The signature is genuine, but the owner it was minted for is not this certificate's holder.
    $this->get(certFileUrl($cert, $owner->id + 999))->assertForbidden();
});

it('rejects a tampered certificate signature', function () {
    $owner = User::factory()->create();
    $cert = issuedCertificateWithPdf($owner);

    $this->get(certFileUrl($cert, $owner->id).'tampered')->assertForbidden();
});

it('rejects an expired certificate signature', function () {
    $owner = User::factory()->create();
    $cert = issuedCertificateWithPdf($owner);
    $url = certFileUrl($cert, $owner->id, 5);

    $this->travel(6)->minutes();

    $this->get($url)->assertForbidden();
});

it('returns 404 for a signed URL to a missing certificate', function () {
    $url = URL::temporarySignedRoute('certificates.file', now()->addMinutes(15), [
        'certificate' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
        'owner' => 1,
    ]);

    $this->get($url)->assertNotFound();
});

it('preserves authorized (admin-minted) certificate downloads end-to-end', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CertificationSeeder::class);

    $holder = User::factory()->create();
    $cert = issuedCertificateWithPdf($holder);

    // A certificate manager (non-super-admin) mints the download for someone else's certificate…
    $manager = User::factory()->create();
    $manager->assignRole('admin');
    Sanctum::actingAs($manager);

    $url = $this->postJson("/api/v1/certificates/{$cert->public_id}/download")
        ->assertOk()->json('data.download_url');

    // …and the resulting owner-bound URL still streams the holder's PDF.
    $this->get($url)->assertOk();
});

// ---------------------------------------------------------------- analytics export file

it('serves an export to a valid signed URL bound to its owner', function () {
    $owner = User::factory()->create();
    $job = completedExport($owner);

    $this->get(exportFileUrl($job, $owner->id))->assertOk();
});

it('refuses a validly-signed export URL bound to another user', function () {
    $owner = User::factory()->create();
    $job = completedExport($owner);

    $this->get(exportFileUrl($job, $owner->id + 999))->assertForbidden();
});

it('rejects a tampered export signature', function () {
    $owner = User::factory()->create();
    $job = completedExport($owner);

    $this->get(exportFileUrl($job, $owner->id).'tampered')->assertForbidden();
});

it('rejects an expired export signature', function () {
    $owner = User::factory()->create();
    $job = completedExport($owner);
    $url = exportFileUrl($job, $owner->id, 5);

    $this->travel(6)->minutes();

    $this->get($url)->assertForbidden();
});
